<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyCalendarDay;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanyTiffinAssignment;
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

    /**
     * Acme and Northwind are the pair a demo is walked through, and they share a
     * kitchen so the vendor's preparation view has two companies to break down.
     */
    public function test_the_two_walkable_companies_share_one_kitchen(): void
    {
        $shared = TiffinService::where('name', 'Annapurna Gourmet Tiffin')->firstOrFail();

        foreach (['Acme Industries', 'Northwind Traders'] as $name) {
            $company = Company::where('name', $name)->firstOrFail();

            $this->assertNotNull($company->activeAssignment, "{$name} is not paired.");
            $this->assertSame(
                $shared->id,
                $company->activeAssignment->tiffin_service_id,
                "{$name} is not on the shared kitchen.",
            );
        }
    }

    /**
     * Two companies demonstrates the meal flow and not the platform: the super
     * admin's directory, the health screen and every report read as a product
     * with two customers, and the pairing screen offers a choice of one.
     */
    public function test_every_company_is_paired_and_configured(): void
    {
        $this->assertEquals(20, Company::count());

        foreach (Company::all() as $company) {
            $this->assertNotNull($company->activeAssignment, "{$company->name} is not paired.");
            $this->assertNotNull($company->setting, "{$company->name} has no settings.");
        }
    }

    /**
     * A shared cutoff makes the scheduler look like it locks the whole platform
     * at one moment, which is the opposite of what the product does.
     */
    public function test_cutoffs_are_not_all_the_same(): void
    {
        $distinct = Company::with('setting')->get()
            ->pluck('setting.cutoff_time')
            ->unique();

        $this->assertGreaterThan(1, $distinct->count());
    }

    /**
     * The second kitchen has no company, and that is the point: without it a
     * demo cannot reach the super admin's pairing decision (one choice is not a
     * choice) or the screen a kitchen sees before it is connected to anyone.
     */
    public function test_it_seeds_a_second_kitchen_paired_with_nobody(): void
    {
        $this->assertEquals(5, TiffinService::count());

        $unpaired = TiffinService::where('name', 'Tulsi Rasoi')->firstOrFail();

        $this->assertSame(
            0,
            CompanyTiffinAssignment::where('tiffin_service_id', $unpaired->id)->count(),
            'the second kitchen is paired, so the unconnected screens cannot be demonstrated',
        );

        $this->assertNotNull(
            User::where('tiffin_service_id', $unpaired->id)->where('role', 'tiffin_admin')->first(),
            'the second kitchen has no login, so nobody can see those screens',
        );
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
        // Two weeks for each kitchen that cooks for somebody. A company whose
        // kitchen has no menu shows "No menu published" on every screen, which
        // is a broken-looking demo rather than a feature.
        $cooking = TiffinService::whereHas('assignments')->count();

        $this->assertSame(4, $cooking);
        $this->assertEquals($cooking * 2, WeeklyMenu::where('status', 'published')->count());
        $this->assertEquals($cooking * 2 * 5, MenuItem::count());

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
            ->assertRedirect('/tiffin-admin/preparation');

        $this->post('/logout');

        $this->post('/login', ['email' => 'hr@acme.test', 'password' => 'demo1234'])
            ->assertRedirect('/company-admin/dashboard');
    }

    public function test_an_employee_signs_in_with_their_email(): void
    {
        $this->post('/login', ['identifier' => 'acme001@demo.test', 'password' => 'demo1234'])
            ->assertRedirect('/employee/dashboard');
    }

    /**
     * The seeded employee who has no address gets no login, which is the
     * product's own rule rather than a hole in the demo: sign-in is by email,
     * so there would be nothing to sign in with.
     */
    public function test_the_employee_without_an_email_has_no_login(): void
    {
        $withoutEmail = Employee::whereNull('email')->get();

        $this->assertNotEmpty($withoutEmail, 'the demo is meant to include one');

        foreach ($withoutEmail as $employee) {
            $this->assertNull($employee->user_id);
        }
    }

    /**
     * The two walkable companies pick their first three employees, and the one
     * of those with no address cannot be given an account, so two land.
     *
     * The other eighteen get one each: enough to sign in and look around, but
     * they are scenery, and sixty employee logins is not a list anybody reads.
     */
    public function test_employee_logins_are_created_for_every_company(): void
    {
        foreach (['Acme Industries', 'Northwind Traders'] as $name) {
            $company = Company::where('name', $name)->firstOrFail();

            $this->assertEquals(2, Employee::where('company_id', $company->id)
                ->whereNotNull('user_id')->count(), "{$name} should have two logins.");
        }

        $others = Company::whereNotIn('name', ['Acme Industries', 'Northwind Traders'])->get();

        $this->assertCount(18, $others);

        foreach ($others as $company) {
            $this->assertEquals(1, Employee::where('company_id', $company->id)
                ->whereNotNull('user_id')->count(), "{$company->name} should have one login.");
        }
    }

    /**
     * The seeder itself, not `db:seed`.
     *
     * Going through the command proved nothing: Laravel's own confirmable guard
     * aborts `db:seed` in production before the seeder is reached, so this test
     * was green with the seeder's guard deleted. `db:seed --force` skips that
     * prompt - which is exactly what a deploy script does - and then the
     * seeder's own check is all that is left.
     */
    public function test_it_refuses_to_run_outside_local_and_testing(): void
    {
        app()['env'] = 'production';

        $before = Company::count();

        $seeder = new DemoSeeder;
        $seeder->setContainer(app());
        $seeder->run();

        $this->assertEquals($before, Company::count());
    }

    /**
     * And the outer guard, which is what stops a plain `php artisan db:seed
     * --class=DemoSeeder` typed on a server by hand.
     */
    public function test_the_command_is_refused_in_production_without_force(): void
    {
        $this->withoutMockingConsoleOutput();

        app()['env'] = 'production';

        $before = Company::count();

        ob_start();
        $exitCode = $this->artisan('db:seed', ['--class' => DemoSeeder::class]);
        ob_end_clean();

        $this->assertNotSame(0, $exitCode, 'db:seed was allowed to proceed in production');
        $this->assertEquals($before, Company::count());
    }

    /**
     * These reasons are shown to a company admin on the roster, and during a
     * rehearsal they read "Leave recorded for the demo" - the word "demo" on
     * screen in front of the audience.
     */
    public function test_no_seeded_reason_mentions_the_demo(): void
    {
        $reasons = array_merge(
            Skip::pluck('reason')->filter()->all(),
            MealAdjustment::pluck('reason')->filter()->all(),
        );

        $this->assertNotEmpty($reasons);

        foreach ($reasons as $reason) {
            $this->assertStringNotContainsStringIgnoringCase('demo', $reason, "reason reads: {$reason}");
            $this->assertStringNotContainsStringIgnoringCase('seeded', $reason);
            $this->assertStringNotContainsStringIgnoringCase('test', $reason);
        }
    }

    /**
     * Each source should read like that source wrote it: an HR entry must not
     * sound like an employee's own note.
     */
    public function test_each_skip_source_has_its_own_wording(): void
    {
        $bySource = Skip::whereNotNull('reason')
            ->get()
            ->groupBy('source')
            ->map(fn ($group) => $group->pluck('reason')->unique()->values()->all());

        // The four hand-entered sources the seeder covers.
        foreach (['leave', 'wfh', 'hr', 'self'] as $source) {
            $this->assertArrayHasKey($source, $bySource->all(), "no seeded skip with source '{$source}'");
        }

        $firstOf = fn (string $s) => $bySource[$s][0];

        $this->assertNotEquals($firstOf('hr'), $firstOf('self'), 'HR and self share wording');
        $this->assertNotEquals($firstOf('leave'), $firstOf('wfh'), 'leave and WFH share wording');
    }
}
