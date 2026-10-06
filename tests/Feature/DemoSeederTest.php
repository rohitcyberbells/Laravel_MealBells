<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyCalendarDay;
use App\Models\CompanyHrmsConnection;
use App\Models\Employee;
use App\Models\HrmsWebhookEvent;
use App\Models\MealAdjustment;
use App\Models\MealCount;
use App\Models\MealCountChange;
use App\Models\MenuItem;
use App\Models\RecurringSkip;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use App\Models\WeeklyMenu;
use Carbon\Carbon;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
    }

    public function test_it_seeds_two_companies_on_one_vendor(): void
    {
        $this->assertEquals(1, TiffinService::count());
        $this->assertEquals(2, Company::count());

        foreach (Company::all() as $company) {
            $this->assertNotNull($company->activeAssignment, "{$company->name} is not paired.");
            $this->assertNotNull($company->setting, "{$company->name} has no settings.");
        }
    }

    public function test_it_seeds_thirty_and_ten_employees(): void
    {
        $counts = Company::all()
            ->mapWithKeys(fn (Company $c) => [$c->name => Employee::where('company_id', $c->id)->count()]);

        $this->assertEquals(30, $counts['Acme Industries']);
        $this->assertEquals(10, $counts['Northwind Traders']);
    }

    public function test_each_company_has_someone_inactive_and_someone_ineligible(): void
    {
        // So the base count never trivially equals the headcount.
        foreach (Company::all() as $company) {
            $this->assertEquals(1, Employee::where('company_id', $company->id)
                ->where('status', 'inactive')->count());

            $this->assertEquals(1, Employee::where('company_id', $company->id)
                ->where('is_meal_eligible', false)->count());
        }
    }

    public function test_it_publishes_a_menu_for_this_week_and_next(): void
    {
        $this->assertEquals(2, WeeklyMenu::where('status', 'published')->count());
        $this->assertEquals(10, MenuItem::count());

        foreach (WeeklyMenu::all() as $menu) {
            $this->assertCount(5, $menu->items);
        }
    }

    public function test_it_seeds_skips_from_every_source(): void
    {
        $sources = Skip::query()->distinct()->pluck('source')->all();

        foreach (['leave', 'wfh', 'hr', 'self', 'recurring'] as $expected) {
            $this->assertContains($expected, $sources, "No '{$expected}' skip was seeded.");
        }

        // And one withdrawn, so a cancelled row is visible too.
        $this->assertGreaterThan(0, Skip::whereNotNull('cancelled_at')->count());
    }

    public function test_it_seeds_a_recurring_rule_per_company(): void
    {
        foreach (Company::all() as $company) {
            $this->assertEquals(1, RecurringSkip::where('company_id', $company->id)
                ->where('active', true)->count());
        }
    }

    public function test_it_seeds_a_locked_day_carrying_a_post_cutoff_change(): void
    {
        foreach (Company::all() as $company) {
            $locked = MealCount::where('company_id', $company->id)
                ->whereNotNull('locked_at')
                ->first();

            $this->assertNotNull($locked, "{$company->name} has no locked snapshot.");
            $this->assertEquals('auto_confirmed', $locked->status);

            $changes = MealCountChange::where('meal_count_id', $locked->id)->get();
            $this->assertCount(2, $changes);

            // The accessor is what the screens show, so the arithmetic is pinned.
            $this->assertEquals(
                $locked->final_expected_count + 3,
                $locked->adjusted_total
            );
        }
    }

    public function test_the_locked_day_is_in_the_past_and_a_meal_day(): void
    {
        foreach (MealCount::all() as $snapshot) {
            $date = Carbon::parse($snapshot->date);

            $this->assertTrue($date->lessThan(now()), 'The locked day should already have passed.');
            $this->assertFalse($date->isWeekend(), 'The locked day should be a meal day.');
        }
    }

    public function test_it_seeds_a_holiday_and_guest_meals_per_company(): void
    {
        foreach (Company::all() as $company) {
            $this->assertEquals(1, CompanyCalendarDay::where('company_id', $company->id)
                ->where('type', 'holiday')->count());

            $this->assertEquals(2, MealAdjustment::where('company_id', $company->id)->count());
        }
    }

    public function test_it_seeds_an_hrms_connection_and_sample_events_for_acme_only(): void
    {
        $acme = Company::where('name', 'Acme Industries')->sole();
        $northwind = Company::where('name', 'Northwind Traders')->sole();

        // One company connected, one not, so the screen shows both states.
        $this->assertNotNull(
            CompanyHrmsConnection::where('company_id', $acme->id)->first()?->webhook_secret
        );
        $this->assertNull(CompanyHrmsConnection::where('company_id', $northwind->id)->first());

        $events = HrmsWebhookEvent::where('company_id', $acme->id)->get();
        $this->assertCount(4, $events);

        // One of each outcome, so the connect screen and health page are not blank.
        $this->assertEqualsCanonicalizing(
            ['applied', 'blocked', 'stale', 'applied'],
            $events->pluck('status')->all()
        );

        $this->assertEquals(0, HrmsWebhookEvent::where('company_id', $northwind->id)->count());

        // The applied event must have a real skip behind it, or the screen shows
        // an outcome with nothing to back it up.
        $applied = $events->firstWhere('external_event_id', 'evt_demo_applied');
        $skip = Skip::where('external_ref', $applied->leave_external_id)->sole();

        $this->assertEquals($applied->result['applied_days'][0], Carbon::parse($skip->date)->toDateString());
        $this->assertEquals('leave', $skip->source);
        $this->assertNull($skip->cancelled_at);
    }

    public function test_employees_carry_an_hrms_reference(): void
    {
        $employee = Employee::where('employee_code', 'ACME001')->sole();

        $this->assertEquals('HR-ACME001', $employee->external_id);
    }

    public function test_every_demo_login_can_actually_sign_in(): void
    {
        // must_change_password would otherwise drop each of them onto the
        // change-password screen before anything could be demonstrated.
        $this->assertEquals(0, User::where('must_change_password', true)->count());

        $this->post('/login', ['email' => 'root@mealbells.test', 'password' => 'demo1234'])
            ->assertRedirect('/super-admin/dashboard');

        $this->post('/logout');

        $this->post('/login', ['email' => 'vendor@mealbells.test', 'password' => 'demo1234'])
            ->assertRedirect('/tiffin-admin/dashboard');

        $this->post('/logout');

        $this->post('/login', ['email' => 'hr@acme.test', 'password' => 'demo1234'])
            ->assertRedirect('/company-admin/dashboard');
    }

    public function test_an_employee_signs_in_with_the_company_and_employee_code(): void
    {
        $acme = Company::where('name', 'Acme Industries')->sole();

        $this->post('/login', [
            'company_code' => $acme->code,
            'login_code' => 'ACME001',
            'password' => 'demo1234',
        ])->assertRedirect('/employee/dashboard');
    }

    public function test_it_creates_three_employee_logins_per_company(): void
    {
        foreach (Company::all() as $company) {
            $this->assertEquals(3, Employee::where('company_id', $company->id)
                ->whereNotNull('user_id')->count());
        }
    }

    public function test_it_refuses_to_run_outside_local_and_testing(): void
    {
        // The seeder writes to the console on refusal, which the mocked output
        // style rejects; the buffer keeps that message out of the test output.
        $this->withoutMockingConsoleOutput();

        app()['env'] = 'production';

        $before = Company::count();

        ob_start();
        $this->seed(DemoSeeder::class);
        ob_end_clean();

        $this->assertEquals($before, Company::count());
    }
}
