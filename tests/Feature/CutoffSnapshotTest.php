<?php

namespace Tests\Feature;

use App\Actions\Meal\ConfirmDailyCount;
use App\Actions\Meal\RecordExtraMeal;
use App\Actions\Meal\RecordSkip;
use App\Models\Company;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\TiffinService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CutoffSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_daily_count_creates_snapshot(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => now()]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        for ($i = 1; $i <= 3; $i++) {
            Employee::create(['company_id' => $company->id, 'employee_code' => "EMP0{$i}", 'name' => "Staff {$i}", 'status' => 'active', 'is_meal_eligible' => true]);
        }

        $date = Carbon::tomorrow()->toDateString();
        $emp1 = Employee::first();

        (new RecordSkip)->execute($company, $emp1, $date, 'leave', 'On Leave', $user);
        (new RecordExtraMeal)->execute($company, $user, $date, 2, 'guest', 'Visitors');

        $action = new ConfirmDailyCount;
        $snapshot = $action->execute($company, $date, $user, false);

        // Expected: 3 (Base) + 2 (Extra) - 1 (Skip) = 4 Final Expected
        $this->assertEquals(4, $snapshot->final_expected_count);
        $this->assertEquals('confirmed', $snapshot->status);
        $this->assertNotNull($snapshot->locked_at);
        $this->assertEquals(1, $snapshot->breakdown['leave']);
    }

    public function test_process_cutoff_command_locks_today_count(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => now()]);

        $this->artisan('mealbells:process-cutoff')
            ->assertExitCode(0);
    }
}
