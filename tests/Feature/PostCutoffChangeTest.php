<?php

namespace Tests\Feature;

use App\Actions\Meal\ConfirmDailyCount;
use App\Actions\Meal\RecordPostCutoffChange;
use App\Models\Company;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\TiffinService;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostCutoffChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_post_cutoff_change_creates_audit_log_and_calculates_adjusted_total(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => now()]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        for ($i = 1; $i <= 5; $i++) {
            Employee::create(['company_id' => $company->id, 'employee_code' => "EMP0{$i}", 'name' => "Staff {$i}", 'status' => 'active', 'is_meal_eligible' => true]);
        }

        $date = Carbon::today()->toDateString();
        $snapshot = (new ConfirmDailyCount)->execute($company, $date, $user, false);

        $this->assertEquals(5, $snapshot->final_expected_count);

        // Record post-cutoff +3 meals
        $action = new RecordPostCutoffChange;
        $change1 = $action->execute($company, $date, 3, 'Sudden client meeting', $user);

        $this->assertEquals(3, $change1->change_quantity);
        $this->assertEquals('Sudden client meeting', $change1->reason);

        // Record post-cutoff -1 meal
        $change2 = $action->execute($company, $date, -1, 'Employee left early', $user);

        $this->assertEquals(-1, $change2->change_quantity);

        // Verify adjusted total (5 + 3 - 1 = 7)
        $snapshot->refresh();
        $this->assertEquals(7, $snapshot->adjusted_total);
        // Original final_expected_count remains unchanged at 5
        $this->assertEquals(5, $snapshot->final_expected_count);
    }

    public function test_post_cutoff_change_fails_if_snapshot_not_locked(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => now()]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        $date = Carbon::today()->toDateString();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot log post-cutoff change: Meal count snapshot is not locked yet.');

        (new RecordPostCutoffChange)->execute($company, $date, 2, 'Late request', $user);
    }
}
