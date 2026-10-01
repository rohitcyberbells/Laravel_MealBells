<?php

namespace Tests\Feature;

use App\Actions\Meal\BulkRecordSkip;
use App\Actions\Meal\CalculateExpectedMeals;
use App\Actions\Meal\CancelExtraMeal;
use App\Actions\Meal\CancelSkip;
use App\Actions\Meal\ConfirmDailyCount;
use App\Actions\Meal\RecordExtraMeal;
use App\Actions\Meal\RecordSkip;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\TiffinService;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CancelAndBulkSkipTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_skip_restores_expected_meal_count(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => '2026-09-01']);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);
        $emp = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'John', 'status' => 'active', 'is_meal_eligible' => true]);

        $date = Carbon::today()->toDateString();

        // 1. Record Skip -> Expected meals drops to 0 (1 base - 1 skip)
        $skip = (new RecordSkip)->execute($company, $emp, $date, 'leave', 'Vacation', $user);
        $expected = (new CalculateExpectedMeals)->execute($company, $date);
        $this->assertEquals(0, $expected['final_expected_count']);
        $this->assertEquals(1, $expected['skip_count']);

        // 2. Cancel Skip -> Expected meals restored to 1
        $cancelledSkip = (new CancelSkip)->execute($company, $skip, $user);
        $this->assertNotNull($cancelledSkip->cancelled_at);
        $this->assertEquals($user->id, $cancelledSkip->cancelled_by);

        $expectedAfterCancel = (new CalculateExpectedMeals)->execute($company, $date);
        $this->assertEquals(1, $expectedAfterCancel['final_expected_count']);
        $this->assertEquals(0, $expectedAfterCancel['skip_count']);
    }

    public function test_cancel_extra_meal_restores_expected_meal_count(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => '2026-09-01']);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);
        Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'John', 'status' => 'active', 'is_meal_eligible' => true]);

        $date = Carbon::today()->toDateString();

        // 1. Record Extra 3 -> Expected = 4 (1 base + 3 extra)
        $extra = (new RecordExtraMeal)->execute($company, $user, $date, 3, 'guest', 'Visitors');
        $expected = (new CalculateExpectedMeals)->execute($company, $date);
        $this->assertEquals(4, $expected['final_expected_count']);
        $this->assertEquals(3, $expected['extra_count']);

        // 2. Cancel Extra Meal -> Expected restored to 1
        $cancelledExtra = (new CancelExtraMeal)->execute($company, $extra, $user);
        $this->assertNotNull($cancelledExtra->cancelled_at);

        $expectedAfterCancel = (new CalculateExpectedMeals)->execute($company, $date);
        $this->assertEquals(1, $expectedAfterCancel['final_expected_count']);
        $this->assertEquals(0, $expectedAfterCancel['extra_count']);
    }

    public function test_record_skip_reactivates_previously_cancelled_row(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => '2026-09-01']);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);
        $emp = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'John', 'status' => 'active', 'is_meal_eligible' => true]);

        $date = Carbon::today()->toDateString();

        // Record skip then cancel it
        $skip = (new RecordSkip)->execute($company, $emp, $date, 'leave', 'Vacation', $user);
        (new CancelSkip)->execute($company, $skip, $user);

        // Re-record skip for same employee & date
        $reactivatedSkip = (new RecordSkip)->execute($company, $emp, $date, 'wfh', 'Working Home', $user);

        // Same ID, cancelled_at restored to null
        $this->assertEquals($skip->id, $reactivatedSkip->id);
        $this->assertNull($reactivatedSkip->cancelled_at);
        $this->assertEquals('wfh', $reactivatedSkip->source);
    }

    public function test_cancel_skip_fails_if_count_is_locked(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => '2026-09-01']);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);
        $emp = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'John', 'status' => 'active', 'is_meal_eligible' => true]);

        $date = Carbon::today()->toDateString();
        $skip = (new RecordSkip)->execute($company, $emp, $date, 'leave', 'Vacation', $user);

        // Lock daily count
        (new ConfirmDailyCount)->execute($company, $date, $user);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot cancel skip: Count is locked for this date.');

        (new CancelSkip)->execute($company, $skip, $user);
    }

    public function test_bulk_record_skip_processes_employees_and_non_meal_days(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => '2026-09-01']);
        CompanySetting::create(['company_id' => $company->id, 'cutoff_time' => '18:00:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5]]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        $emp1 = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'Staff 1', 'status' => 'active', 'is_meal_eligible' => true]);
        $emp2 = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP2', 'name' => 'Staff 2', 'status' => 'active', 'is_meal_eligible' => true]);

        // Friday: 2026-10-02 (Meal day), Saturday: 2026-10-03 (Non-meal day)
        $dates = ['2026-10-02', '2026-10-03'];

        $action = new BulkRecordSkip;
        $response = $action->execute($company, [$emp1->id, $emp2->id], $dates, 'hr', 'Company Outing', $user);

        $this->assertEquals(4, $response['total_processed']);
        $this->assertEquals(2, $response['created_count']); // Friday for emp1 & emp2
        $this->assertEquals(2, $response['non_meal_day_count']); // Saturday for emp1 & emp2

        // Re-running bulk skip returns already_skipped_count for Friday
        $secondRun = $action->execute($company, [$emp1->id], ['2026-10-02'], 'hr', 'Repeat', $user);
        $this->assertEquals(1, $secondRun['already_skipped_count']);
    }

    public function test_bulk_skip_rejects_ranges_exceeding_31_days(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);

        $dates = [];
        for ($i = 0; $i < 35; $i++) {
            $dates[] = Carbon::today()->addDays($i)->toDateString();
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bulk skip date range cannot exceed 31 days.');

        (new BulkRecordSkip)->execute($company, [1], $dates);
    }
}
