<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealAdjustment;
use App\Models\MealCount;
use App\Models\MealCountChange;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The dashboard's count and week-ahead strip.
 *
 * The controller computed todayStats and forecast and the page declared
 * neither, so CalculateExpectedMeals ran eight times a load for figures that
 * were thrown away. These assert the numbers are real and that the page reads
 * them, which is what stops it going dead again.
 *
 * Clock frozen at Monday 2026-10-05 08:00 IST, cutoff 11:00.
 */
class CompanyDashboardForecastTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected TiffinService $tiffin;

    /** @var array<int, Employee> */
    protected array $employees = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);

        $this->tiffin = TiffinService::create(['name' => 'Tiffin Co', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        $this->company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanyTiffinAssignment::create([
            'company_id' => $this->company->id, 'tiffin_service_id' => $this->tiffin->id,
            'is_active' => true, 'assigned_at' => now(),
        ]);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->admin = User::create([
            'name' => 'Alpha HR', 'email' => 'hr@alpha.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        // Ten eligible, one ineligible, one inactive - so "eligible" is a real
        // number rather than a head count.
        for ($i = 1; $i <= 10; $i++) {
            $this->employees[$i] = Employee::create([
                'company_id' => $this->company->id, 'employee_code' => 'EMP'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => "Person {$i}", 'status' => 'active', 'is_meal_eligible' => true,
            ]);
        }

        Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'EMP090',
            'name' => 'Not eligible', 'status' => 'active', 'is_meal_eligible' => false,
        ]);

        Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'EMP091',
            'name' => 'Inactive', 'status' => 'inactive', 'is_meal_eligible' => true,
        ]);
    }

    /** @return array<string, mixed> */
    protected function props(): array
    {
        return $this->actingAs($this->admin)->get('/company-admin/dashboard')
            ->getOriginalContent()->getData()['page']['props'];
    }

    public function test_the_dashboard_sends_todays_stats_and_a_forecast(): void
    {
        $this->actingAs($this->admin)->get('/company-admin/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('CompanyAdmin/Dashboard')
                ->has('todayStats')
                ->has('forecast', 7)
                ->etc()
            );
    }

    /**
     * Expected = eligible − skips + extra, the same arithmetic the engine locks
     * at cutoff.
     */
    public function test_todays_expected_count_is_base_minus_skips_plus_extra(): void
    {
        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employees[1]->id,
            'date' => '2026-10-05', 'source' => 'hr',
        ]);
        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employees[2]->id,
            'date' => '2026-10-05', 'source' => 'self',
        ]);

        MealAdjustment::create([
            'company_id' => $this->company->id, 'date' => '2026-10-05',
            'quantity' => 3, 'type' => 'guest', 'created_by' => $this->admin->id,
        ]);

        $today = $this->props()['todayStats'];

        $this->assertTrue($today['is_meal_day']);
        $this->assertEquals(10, $today['base_eligible_count'], 'ineligible and inactive are not counted');
        $this->assertEquals(2, $today['skip_count']);
        $this->assertEquals(3, $today['extra_count']);
        $this->assertEquals(10 - 2 + 3, $today['final_expected_count']);
    }

    public function test_everyone_eligible_is_counted_by_default(): void
    {
        $today = $this->props()['todayStats'];

        $this->assertEquals(0, $today['skip_count']);
        $this->assertEquals(10, $today['final_expected_count']);
    }

    /**
     * Once locked, the figure shown has to be what the kitchen actually holds -
     * the snapshot plus any change agreed after cutoff - not a recalculation
     * from employees that has moved on since.
     *
     * adjusted_total is an accessor, not a column: final_expected_count plus the
     * sum of post-cutoff changes.
     */
    public function test_a_locked_day_reports_the_number_the_kitchen_holds(): void
    {
        $count = MealCount::create([
            'company_id' => $this->company->id, 'tiffin_service_id' => $this->tiffin->id,
            'date' => '2026-10-05', 'base_eligible_count' => 10, 'skip_count' => 0, 'extra_count' => 0,
            'final_expected_count' => 10, 'breakdown' => [],
            'status' => 'confirmed', 'locked_at' => now(),
        ]);

        // Two more agreed with the vendor after the count closed.
        MealCountChange::create([
            'meal_count_id' => $count->id, 'change_quantity' => 2,
            'reason' => 'Late guests', 'requested_by' => $this->admin->id,
        ]);

        $today = $this->props()['todayStats'];

        $this->assertTrue($today['is_locked']);
        $this->assertEquals('confirmed', $today['status']);
        $this->assertEquals(12, $today['adjusted_total'], 'snapshot plus the post-cutoff change');

        // And a skip recorded afterwards must not move the locked figure.
        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employees[1]->id,
            'date' => '2026-10-05', 'source' => 'hr',
        ]);

        $this->assertEquals(12, $this->props()['todayStats']['adjusted_total']);
    }

    /**
     * An open day has no snapshot, so the figure is the live calculation.
     */
    public function test_an_open_day_reports_the_live_calculation(): void
    {
        $today = $this->props()['todayStats'];

        $this->assertFalse($today['is_locked']);
        $this->assertEquals('open', $today['status']);
        $this->assertEquals($today['final_expected_count'], $today['adjusted_total']);
    }

    public function test_the_forecast_covers_the_next_seven_days_in_order(): void
    {
        $dates = collect($this->props()['forecast'])->pluck('date');

        $this->assertEquals([
            '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08',
            '2026-10-09', '2026-10-10', '2026-10-11',
        ], $dates->all());
    }

    /**
     * The strip hides non-meal days, so each row has to say whether it is one.
     */
    public function test_the_forecast_marks_the_weekend_as_a_non_meal_day(): void
    {
        $byDate = collect($this->props()['forecast'])->keyBy('date');

        $this->assertTrue($byDate['2026-10-09']['is_meal_day'], 'Friday');
        $this->assertFalse($byDate['2026-10-10']['is_meal_day'], 'Saturday');
        $this->assertFalse($byDate['2026-10-11']['is_meal_day'], 'Sunday');
    }

    public function test_a_future_skip_shows_in_that_days_forecast_only(): void
    {
        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employees[1]->id,
            'date' => '2026-10-08', 'source' => 'leave', 'external_ref' => 'cp:leave:x1',
        ]);

        $byDate = collect($this->props()['forecast'])->keyBy('date');

        $this->assertEquals(1, $byDate['2026-10-08']['skip_count']);
        $this->assertEquals(9, $byDate['2026-10-08']['final_expected_count']);

        $this->assertEquals(0, $byDate['2026-10-07']['skip_count']);
        $this->assertEquals(10, $byDate['2026-10-07']['final_expected_count']);
    }

    public function test_another_companys_numbers_do_not_leak_in(): void
    {
        $other = Company::create(['name' => 'Beta Corp', 'code' => 'BETA1']);
        CompanySetting::create([
            'company_id' => $other->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        for ($i = 1; $i <= 50; $i++) {
            Employee::create([
                'company_id' => $other->id, 'employee_code' => 'BETA'.$i,
                'name' => "Beta {$i}", 'status' => 'active', 'is_meal_eligible' => true,
            ]);
        }

        $this->assertEquals(10, $this->props()['todayStats']['base_eligible_count']);
    }

    /**
     * The page and the controller have to agree, or this goes dead again - which
     * is exactly how it was found.
     */
    public function test_the_page_reads_both_props(): void
    {
        $dashboard = file_get_contents(resource_path('js/Pages/CompanyAdmin/Dashboard.vue'));

        $this->assertStringContainsString('todayStats:', $dashboard);
        $this->assertStringContainsString('forecast:', $dashboard);
        // And actually renders them, not merely declares them.
        $this->assertStringContainsString('todayStats.base_eligible_count', $dashboard);
        $this->assertStringContainsString('expectedFor(day)', $dashboard);
        $this->assertStringContainsString('forecastDays', $dashboard);

        $props = $this->props();
        $this->assertArrayHasKey('todayStats', $props);
        $this->assertArrayHasKey('forecast', $props);
    }

    /**
     * A tile showing "30" with "−4" beside it, against a base of 28, reads as
     * arithmetic that does not add up - the extra meals were missing from the
     * tile and only present in the tooltip.
     */
    public function test_a_forecast_tile_can_show_both_adjustments(): void
    {
        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employees[1]->id,
            'date' => '2026-10-08', 'source' => 'hr',
        ]);
        MealAdjustment::create([
            'company_id' => $this->company->id, 'date' => '2026-10-08',
            'quantity' => 4, 'type' => 'guest', 'created_by' => $this->admin->id,
        ]);

        $day = collect($this->props()['forecast'])->firstWhere('date', '2026-10-08');

        // Both numbers the tile needs are in the prop.
        $this->assertEquals(1, $day['skip_count']);
        $this->assertEquals(4, $day['extra_count']);
        $this->assertEquals(10 - 1 + 4, $day['final_expected_count']);

        $dashboard = file_get_contents(resource_path('js/Pages/CompanyAdmin/Dashboard.vue'));

        // And the tile renders both, not only the skips.
        $this->assertStringContainsString('−{{ day.skip_count }}', $dashboard);
        $this->assertStringContainsString('+{{ day.extra_count }}', $dashboard);
        // The tooltip spells the whole equation out.
        $this->assertStringContainsString('= ${expectedFor(day)}', $dashboard);
    }

    /**
     * A day that moved in neither direction says so instead of showing "−0 +0".
     */
    public function test_an_untouched_day_shows_no_adjustments(): void
    {
        $day = collect($this->props()['forecast'])->firstWhere('date', '2026-10-08');

        $this->assertEquals(0, $day['skip_count']);
        $this->assertEquals(0, $day['extra_count']);

        $dashboard = file_get_contents(resource_path('js/Pages/CompanyAdmin/Dashboard.vue'));
        $this->assertStringContainsString('dayDelta(day)', $dashboard);
        $this->assertStringContainsString('>full<', $dashboard);
    }

    /**
     * `new Date('2026-10-08')` is UTC midnight, which renders as the 7th in any
     * behind-UTC timezone, so the strip must not parse dates that way.
     */
    public function test_the_page_parses_forecast_dates_as_plain_calendar_dates(): void
    {
        $dashboard = file_get_contents(resource_path('js/Pages/CompanyAdmin/Dashboard.vue'));

        $this->assertStringNotContainsString('new Date(day.date)', $dashboard);
        $this->assertStringContainsString("iso.split('-').map(Number)", $dashboard);
    }

    public function test_a_company_with_no_tiffin_service_still_renders(): void
    {
        CompanyTiffinAssignment::where('company_id', $this->company->id)->update(['is_active' => false]);

        $this->actingAs($this->admin)->get('/company-admin/dashboard')->assertStatus(200);
    }
}
