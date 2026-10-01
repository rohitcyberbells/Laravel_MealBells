<?php

namespace Tests\Feature;

use App\Actions\Meal\ConfirmDailyCount;
use App\Actions\Meal\RecordExtraMeal;
use App\Actions\Meal\RecordSkip;
use App\Events\DailyCountConfirmed;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealCount;
use App\Models\TiffinService;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SafetyFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_mon_fri_company_does_not_create_snapshot_on_saturday(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Mon-Fri', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => '2026-09-01']);
        CompanySetting::create(['company_id' => $company->id, 'cutoff_time' => '10:30:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5]]);

        // Saturday date: 2026-10-03
        $saturday = '2026-10-03';
        Carbon::setTestNow(Carbon::parse('2026-10-03 11:00:00', 'Asia/Kolkata'));

        $this->artisan('mealbells:process-cutoff')->assertExitCode(0);

        // Assert no snapshot created for Saturday
        $this->assertEquals(0, MealCount::where('company_id', $company->id)->where('date', $saturday)->count());

        // Assert ConfirmDailyCount action throws exception on non-meal day
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Cannot confirm daily count: Date {$saturday} is not a working meal day for this company.");

        (new ConfirmDailyCount)->execute($company, $saturday);
    }

    public function test_mon_sat_company_creates_snapshot_on_saturday(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'SixDay Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => '2026-09-01']);
        CompanySetting::create(['company_id' => $company->id, 'cutoff_time' => '10:30:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5, 6]]);

        // Saturday date: 2026-10-03
        $saturday = '2026-10-03';
        Carbon::setTestNow(Carbon::parse('2026-10-03 11:00:00', 'Asia/Kolkata'));

        $this->artisan('mealbells:process-cutoff')->assertExitCode(0);

        // Assert snapshot IS created for Saturday
        $this->assertEquals(1, MealCount::where('company_id', $company->id)->where('date', $saturday)->count());
    }

    public function test_skip_and_extra_meal_rejected_after_snapshot_is_locked(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => '2026-09-01']);
        CompanySetting::create(['company_id' => $company->id, 'cutoff_time' => '12:00:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5]]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);
        $emp = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'Staff 1', 'status' => 'active', 'is_meal_eligible' => true]);

        // Monday date: 2026-10-05
        $monday = '2026-10-05';
        (new ConfirmDailyCount)->execute($company, $monday, $user);

        // Attempting skip after lock throws Exception
        try {
            (new RecordSkip)->execute($company, $emp, $monday, 'leave', 'Vacation', $user);
            $this->fail('Expected Exception for skip on locked count was not thrown.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Count is locked for this date', $e->getMessage());
        }

        // Attempting extra meal after lock throws Exception
        try {
            (new RecordExtraMeal)->execute($company, $user, $monday, 2, 'guest', 'Late guests');
            $this->fail('Expected Exception for extra meal on locked count was not thrown.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Count is locked for this date', $e->getMessage());
        }
    }

    public function test_command_is_idempotent_and_dispatches_event_once(): void
    {
        Event::fake([DailyCountConfirmed::class]);

        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => '2026-09-01']);
        CompanySetting::create(['company_id' => $company->id, 'cutoff_time' => '10:30:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5]]);

        // Monday 2026-10-05 at 11:00 AM
        Carbon::setTestNow(Carbon::parse('2026-10-05 11:00:00', 'Asia/Kolkata'));

        // Run command twice
        $this->artisan('mealbells:process-cutoff')->assertExitCode(0);
        $this->artisan('mealbells:process-cutoff')->assertExitCode(0);

        // Assert exactly 1 row exists and event dispatched exactly once
        $this->assertEquals(1, MealCount::where('company_id', $company->id)->where('date', '2026-10-05')->count());
        Event::assertDispatchedTimes(DailyCountConfirmed::class, 1);
    }

    public function test_process_cutoff_catches_up_if_server_reboots_after_cutoff_time(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => '2026-09-01']);
        CompanySetting::create(['company_id' => $company->id, 'cutoff_time' => '10:30:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5]]);

        // Cutoff was 10:30 AM, but server catches up at 10:50 AM
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:50:00', 'Asia/Kolkata'));

        $this->artisan('mealbells:process-cutoff')->assertExitCode(0);

        // Assert snapshot was locked correctly during catch-up
        $snapshot = MealCount::where('company_id', $company->id)->where('date', '2026-10-05')->first();
        $this->assertNotNull($snapshot);
        $this->assertEquals('auto_confirmed', $snapshot->status);
        $this->assertNotNull($snapshot->locked_at);
    }

    public function test_company_tiffin_assignment_scope_active_on(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);

        $assignment = CompanyTiffinAssignment::create([
            'company_id' => $company->id,
            'tiffin_service_id' => $tiffin->id,
            'is_active' => true,
            'assigned_at' => '2026-09-15',
            'unassigned_at' => '2026-09-30',
        ]);

        // Active on 2026-09-20
        $this->assertTrue(CompanyTiffinAssignment::activeOn('2026-09-20')->where('id', $assignment->id)->exists());

        // Inactive on 2026-09-10 (before assigned_at)
        $this->assertFalse(CompanyTiffinAssignment::activeOn('2026-09-10')->where('id', $assignment->id)->exists());

        // Inactive on 2026-10-01 (after unassigned_at)
        $this->assertFalse(CompanyTiffinAssignment::activeOn('2026-10-01')->where('id', $assignment->id)->exists());
    }
}
