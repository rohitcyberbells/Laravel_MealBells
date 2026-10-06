<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\Skip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdoptionReportPageTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected User $adminA;

    protected Employee $empA1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->companyA->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->adminA = User::create([
            'name' => 'Admin A', 'email' => 'admin@a.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyA->id,
        ]);

        $this->empA1 = Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP101',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    /** @return array<string, mixed> */
    protected function report(): array
    {
        $response = $this->actingAs($this->adminA)->get('/company-admin/reports/adoption');
        $response->assertStatus(200);

        return $response->getOriginalContent()->getData()['page']['props']['report'];
    }

    public function test_the_rate_is_null_rather_than_a_division_by_zero_when_there_are_no_skips(): void
    {
        $report = $this->report();

        $this->assertEquals(0, $report['total_active_skips']);

        // The page relies on this being null to show a dash instead of a bare
        // per-cent sign, so the contract is pinned here.
        $this->assertNull($report['self_service_pct']);
    }

    public function test_the_rate_is_computed_once_skips_exist(): void
    {
        Skip::create([
            'company_id' => $this->companyA->id, 'employee_id' => $this->empA1->id,
            'date' => '2026-10-05', 'source' => 'self',
        ]);

        Skip::create([
            'company_id' => $this->companyA->id, 'employee_id' => $this->empA1->id,
            // Inside the default window, which is today-6..today.
            'date' => '2026-10-02', 'source' => 'hr',
        ]);

        $report = $this->report();

        $this->assertEquals(2, $report['total_active_skips']);
        $this->assertEquals(50.0, $report['self_service_pct']);
    }

    public function test_cancelled_skips_do_not_count_towards_the_rate(): void
    {
        $skip = Skip::create([
            'company_id' => $this->companyA->id, 'employee_id' => $this->empA1->id,
            'date' => '2026-10-05', 'source' => 'self',
        ]);
        $skip->update(['cancelled_at' => now(), 'cancelled_by' => $this->adminA->id]);

        $report = $this->report();

        $this->assertEquals(0, $report['total_active_skips']);
        $this->assertNull($report['self_service_pct']);
    }
}
