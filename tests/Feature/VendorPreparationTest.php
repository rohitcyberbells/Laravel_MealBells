<?php

namespace Tests\Feature;

use App\Actions\Meal\BuildVendorPreparationView;
use App\Actions\Meal\ConfirmDailyCount;
use App\Actions\Meal\RecordPostCutoffChange;
use App\Actions\Meal\RecordSkip;
use App\Models\Company;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\TiffinService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class VendorPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_prep_view_returns_correct_totals_and_enforces_privacy(): void
    {
        $tiffin1 = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $tiffin2 = TiffinService::create(['name' => 'Star Tiffin', 'address' => 'Addr', 'contact_phone' => '0987654321']);

        $companyA = Company::create(['name' => 'Company A', 'address' => 'Addr', 'contact_phone' => '1111111111']);
        $companyB = Company::create(['name' => 'Company B', 'address' => 'Addr', 'contact_phone' => '2222222222']);

        CompanyTiffinAssignment::create(['company_id' => $companyA->id, 'tiffin_service_id' => $tiffin1->id, 'is_active' => true, 'assigned_at' => '2026-09-01']);
        CompanyTiffinAssignment::create(['company_id' => $companyB->id, 'tiffin_service_id' => $tiffin2->id, 'is_active' => true, 'assigned_at' => '2026-09-01']);

        $userA = User::create(['name' => 'Admin A', 'email' => 'admina@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $companyA->id]);
        $emp1 = Employee::create(['company_id' => $companyA->id, 'employee_code' => 'EMP1', 'name' => 'John Doe', 'email' => 'john@secret.com', 'status' => 'active', 'is_meal_eligible' => true]);

        $date = Carbon::today()->toDateString();
        (new RecordSkip)->execute($companyA, $emp1, $date, 'leave', 'Confidential Medical Leave', $userA);

        $action = new BuildVendorPreparationView;
        $response = $action->execute($tiffin1, $date);

        // 1. Verify Company A is present for Tiffin 1, but Company B is NOT
        $this->assertEquals(1, count($response['companies']));
        $this->assertEquals($companyA->id, $response['companies'][0]['company_id']);

        // 2. Verify Privacy (NO employee name, email, code or skip reason anywhere in JSON response)
        $jsonString = json_encode($response);
        $this->assertStringNotContainsString('John Doe', $jsonString);
        $this->assertStringNotContainsString('john@secret.com', $jsonString);
        $this->assertStringNotContainsString('EMP1', $jsonString);
        $this->assertStringNotContainsString('Confidential Medical Leave', $jsonString);

        // 3. Unlocked date should mark is_estimate = true
        $this->assertTrue($response['summary']['is_estimate']);
        $this->assertEquals('estimate', $response['companies'][0]['status']);
    }

    public function test_vendor_prep_view_uses_locked_snapshot_and_late_changes(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => '2026-09-01']);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        for ($i = 1; $i <= 10; $i++) {
            Employee::create(['company_id' => $company->id, 'employee_code' => "EMP0{$i}", 'name' => "Staff {$i}", 'status' => 'active', 'is_meal_eligible' => true]);
        }

        $date = Carbon::today()->toDateString();
        (new ConfirmDailyCount)->execute($company, $date, $user);

        // Record late change +3
        (new RecordPostCutoffChange)->execute($company, $date, 3, 'Sudden visitors', $user);

        $response = (new BuildVendorPreparationView)->execute($tiffin, $date);

        $this->assertFalse($response['summary']['is_estimate']);
        $this->assertEquals(13, $response['summary']['total_meals']); // 10 base + 3 late change
        $this->assertEquals(10, $response['companies'][0]['final_expected_count']);
        $this->assertEquals(13, $response['companies'][0]['adjusted_total']);
        $this->assertEquals(1, count($response['companies'][0]['late_changes']));
        $this->assertEquals(3, $response['companies'][0]['late_changes'][0]['change_quantity']);
    }

    public function test_vendor_prep_view_rejects_dates_outside_allowed_window(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);

        $outdatedDate = Carbon::today()->subDays(35)->toDateString();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Date is out of allowed window');

        (new BuildVendorPreparationView)->execute($tiffin, $outdatedDate);
    }
}
