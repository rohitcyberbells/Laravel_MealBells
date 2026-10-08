<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyCalendarDay;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealCount;
use App\Models\MenuItem;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use App\Models\WeeklyMenu;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Budgets for the four pages that walk a span of days.
 *
 * Every one of them used to query inside its loop, so the cost grew with the
 * span rather than with the page. The loops now preload by range, and these
 * ceilings are what stops a future per-day query from quietly reintroducing the
 * problem - the kind of regression that is invisible on a seeded laptop and
 * only shows up under real data.
 *
 * The fixture deliberately carries two companies, two weeks of menu, skips and
 * a calendar override: a count taken against an empty database proves nothing,
 * because the loop bodies that cost the most never run.
 *
 * The ceilings are the counts measured against *this* fixture, not aspirations,
 * which is why they sit below the figures measured on the development database
 * (17 / 33 / 25 / 24 there, against more companies and more history). A ceiling
 * set at the real figure would not bite here. If an intentional change raises
 * one, raise the number with it and say why.
 */
class PageQueryCountTest extends TestCase
{
    use RefreshDatabase;

    protected TiffinService $tiffin;

    /** @var array<int, Company> */
    protected array $companies = [];

    protected User $employeeUser;

    protected User $companyAdmin;

    protected User $superAdmin;

    protected User $tiffinAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);

        $this->tiffin = TiffinService::create([
            'name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890',
        ]);

        // Two weeks of published menu, because a seven-day strip spans two.
        foreach (['2026-10-05', '2026-10-12'] as $weekStart) {
            $menu = WeeklyMenu::create([
                'tiffin_service_id' => $this->tiffin->id,
                'week_start_date' => $weekStart,
                'status' => 'published',
            ]);

            foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $day) {
                MenuItem::create([
                    'weekly_menu_id' => $menu->id,
                    'day_of_week' => $day,
                    'meal_description' => "{$day} thali",
                ]);
            }
        }

        foreach ([1, 2] as $n) {
            $company = Company::create(['name' => "Company {$n}", 'code' => "CO000{$n}"]);

            CompanySetting::create([
                'company_id' => $company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
                'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            ]);

            CompanyTiffinAssignment::create([
                'company_id' => $company->id, 'tiffin_service_id' => $this->tiffin->id,
                'is_active' => true, 'assigned_at' => '2026-09-01',
            ]);

            // A holiday and a working Saturday, so both branches of the calendar
            // override are exercised inside the loops.
            CompanyCalendarDay::create([
                'company_id' => $company->id, 'date' => '2026-10-08', 'type' => 'holiday', 'note' => 'Festival',
            ]);
            CompanyCalendarDay::create([
                'company_id' => $company->id, 'date' => '2026-10-10', 'type' => 'working_day', 'note' => 'Makeup',
            ]);

            for ($i = 1; $i <= 12; $i++) {
                $employee = Employee::create([
                    'company_id' => $company->id,
                    'employee_code' => "C{$n}EMP".str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                    'name' => "Person {$n}-{$i}", 'status' => 'active', 'is_meal_eligible' => true,
                ]);

                if ($i <= 3) {
                    Skip::create([
                        'company_id' => $company->id, 'employee_id' => $employee->id,
                        'date' => '2026-10-06', 'source' => 'self',
                    ]);
                }
            }

            MealCount::create([
                'company_id' => $company->id, 'tiffin_service_id' => $this->tiffin->id,
                'date' => '2026-10-02', 'base_eligible_count' => 12, 'skip_count' => 0, 'extra_count' => 0,
                'final_expected_count' => 12, 'locked_at' => '2026-10-02 11:00:00',
                'breakdown' => [], 'status' => 'locked', 'lock_type' => 'auto',
            ]);

            $this->companies[$n] = $company;
        }

        $this->employeeUser = User::create([
            'name' => 'Alice', 'email' => 'alice@co1.test', 'password' => bcrypt('password'),
            'role' => 'employee', 'company_id' => $this->companies[1]->id, 'login_code' => 'C1EMP001',
        ]);

        Employee::where('company_id', $this->companies[1]->id)
            ->where('employee_code', 'C1EMP001')
            ->update(['user_id' => $this->employeeUser->id]);

        $this->companyAdmin = User::create([
            'name' => 'HR One', 'email' => 'hr@co1.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companies[1]->id,
        ]);

        $this->superAdmin = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test', 'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);

        $this->tiffinAdmin = User::create([
            'name' => 'Vendor', 'email' => 'vendor@royal.test', 'password' => bcrypt('password'),
            'role' => 'tiffin_admin', 'tiffin_service_id' => $this->tiffin->id,
        ]);
    }

    /**
     * Load a page as a user and return how many queries it took.
     *
     * Exactly one request is measured, and nothing is loaded beforehand. A
     * warm-up request would be wrong here: the per-request calendar memo is a
     * container singleton, and the test application is not rebuilt between two
     * get() calls, so a second request reads a memo the first one filled and
     * reports a figure no real request ever achieves. Measured that way, this
     * test went on passing with the range preload deleted.
     */
    protected function queriesFor(User $user, string $url): int
    {
        $count = 0;

        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->actingAs(User::findOrFail($user->id))->get($url)->assertStatus(200);

        return $count;
    }

    public function test_the_employee_dashboard_stays_within_its_query_budget(): void
    {
        $queries = $this->queriesFor($this->employeeUser, '/employee/dashboard');

        $this->assertLessThanOrEqual(
            15,
            $queries,
            "The employee dashboard took {$queries} queries. It was 76 before the seven-day strip ".
            'preloaded by range; a rise here usually means a query moved back inside that loop.'
        );
    }

    public function test_the_company_admin_dashboard_stays_within_its_query_budget(): void
    {
        $queries = $this->queriesFor($this->companyAdmin, '/company-admin/dashboard');

        $this->assertLessThanOrEqual(
            32,
            $queries,
            "The company admin dashboard took {$queries} queries. Most of what remains is ".
            'CalculateExpectedMeals once per forecast day; a rise beyond that is a new per-day query.'
        );
    }

    public function test_the_super_admin_health_page_stays_within_its_query_budget(): void
    {
        // Past the 11:00 cutoff, otherwise the per-company "is today's snapshot
        // missing?" check is never reached and the batching this guards is not
        // exercised at all.
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'Asia/Kolkata'));

        $queries = $this->queriesFor($this->superAdmin, '/super-admin/health');

        $this->assertLessThanOrEqual(
            20,
            $queries,
            "The health page took {$queries} queries. It grows with the number of companies if the ".
            'per-company checks stop being batched, which is exactly what this guards.'
        );
    }

    public function test_the_vendor_preparation_page_stays_within_its_query_budget(): void
    {
        $queries = $this->queriesFor($this->tiffinAdmin, '/tiffin-admin/preparation');

        $this->assertLessThanOrEqual(
            24,
            $queries,
            "The preparation page took {$queries} queries. The controller and the action share one ".
            'timezone lookup; a rise suggests that lookup is being repeated again.'
        );
    }
}
