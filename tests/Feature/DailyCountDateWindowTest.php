<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The daily count screen's date window must match what MealGuard will actually
 * accept, or the picker refuses days the engine would allow.
 */
class DailyCountDateWindowTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $tiffin = TiffinService::create(['name' => 'Tiffin Co']);
        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->companyA->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $this->companyA->id, 'tiffin_service_id' => $tiffin->id,
            'is_active' => true, 'assigned_at' => '2026-09-01',
        ]);

        $this->adminA = User::create([
            'name' => 'Admin A', 'email' => 'admin@a.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyA->id,
        ]);

        Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP101',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    protected function visit(string $date)
    {
        return $this->actingAs($this->adminA)->get("/company-admin/daily?date={$date}");
    }

    public function test_a_date_inside_the_engines_advance_limit_is_accepted(): void
    {
        // Frozen clock is 2026-10-05, the engine allows 60 days, and 30 days out
        // used to be refused because the screen assumed 14.
        $response = $this->visit('2026-11-04');

        $response->assertStatus(200);
        $this->assertEquals(
            '2026-11-04',
            $response->getOriginalContent()->getData()['page']['props']['date']
        );
    }

    public function test_the_window_edge_matches_the_configured_advance_limit(): void
    {
        $limit = (int) config('mealbells.advance_limit_days', 60);
        $edge = now()->copy()->addDays($limit)->toDateString();
        $beyond = now()->copy()->addDays($limit + 1)->toDateString();

        $this->assertEquals($edge, $this->visit($edge)
            ->getOriginalContent()->getData()['page']['props']['date']);

        // Past the limit the screen bounces back to today rather than showing a
        // date the engine would reject anyway.
        $this->visit($beyond)->assertRedirect(route('company-admin.daily.index'));
    }

    public function test_a_date_too_far_in_the_past_is_bounced(): void
    {
        $this->visit(now()->copy()->subDays(30)->toDateString())
            ->assertRedirect(route('company-admin.daily.index'));
    }

    public function test_a_recent_past_date_is_still_viewable(): void
    {
        $response = $this->visit(now()->copy()->subDays(3)->toDateString());

        $response->assertStatus(200);
    }
}
