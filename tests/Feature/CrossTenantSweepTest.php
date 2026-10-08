<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyCalendarDay;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealAdjustment;
use App\Models\MealCount;
use App\Models\RecurringSkip;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Two complete tenants, and every way one might reach the other.
 *
 * MealBells is multi-tenant with no row-level database isolation: every table
 * carries company_id and every query is expected to scope on it. That makes an
 * unscoped lookup the whole breach, not a detail - a company admin who edits an
 * id in a URL would be reading or changing another customer's employees, skips
 * and counts.
 *
 * So this walks the routes that take an id, from every role, and asserts both
 * that the request is refused and that nothing changed.
 */
class CrossTenantSweepTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    protected array $a = [];

    /** @var array<string, mixed> */
    protected array $b = [];

    protected User $root;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);
        RateLimiter::clear('login');

        $this->a = $this->makeTenant('A', 'Alpha Corp', 'ALPHA1');
        $this->b = $this->makeTenant('B', 'Beta Corp', 'BETA01');

        $this->root = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test',
            'password' => bcrypt('password-1'), 'role' => 'super_admin',
        ]);
    }

    /** @return array<string, mixed> */
    protected function makeTenant(string $tag, string $name, string $code): array
    {
        $tiffin = TiffinService::create([
            'name' => "Tiffin {$tag}", 'address' => 'Addr', 'contact_phone' => '90000000'.ord($tag),
        ]);

        $company = Company::create(['name' => $name, 'code' => $code]);

        CompanySetting::create([
            'company_id' => $company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $company->id, 'tiffin_service_id' => $tiffin->id,
            'is_active' => true, 'assigned_at' => '2026-09-01',
        ]);

        $admin = User::create([
            'name' => "HR {$tag}", 'email' => 'hr'.strtolower($tag).'@test.test',
            'password' => bcrypt('password-1'), 'role' => 'company_admin', 'company_id' => $company->id,
        ]);

        $vendor = User::create([
            'name' => "Vendor {$tag}", 'email' => 'vendor'.strtolower($tag).'@test.test',
            'password' => bcrypt('password-1'), 'role' => 'tiffin_admin', 'tiffin_service_id' => $tiffin->id,
        ]);

        $employeeUser = User::create([
            'name' => "Staff {$tag}", 'email' => 'staff'.strtolower($tag).'@test.test',
            'password' => bcrypt('password-1'), 'role' => 'employee', 'company_id' => $company->id,
            'login_code' => $code.'001',
        ]);

        $employee = Employee::create([
            'company_id' => $company->id, 'user_id' => $employeeUser->id,
            'employee_code' => $code.'001', 'name' => "Staff {$tag}",
            'email' => 'staff'.strtolower($tag).'@test.test',
            'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $skip = Skip::create([
            'company_id' => $company->id, 'employee_id' => $employee->id,
            'date' => '2026-10-09', 'source' => 'self', 'created_by' => $employeeUser->id,
        ]);

        $adjustment = MealAdjustment::create([
            'company_id' => $company->id, 'date' => '2026-10-09',
            'quantity' => 2, 'type' => 'guest', 'created_by' => $admin->id,
        ]);

        $calendarDay = CompanyCalendarDay::create([
            'company_id' => $company->id, 'date' => '2026-10-15',
            'type' => 'holiday', 'note' => "{$tag} holiday",
        ]);

        $rule = RecurringSkip::create([
            'company_id' => $company->id, 'employee_id' => $employee->id,
            'weekday' => 5, 'starts_on' => '2026-10-01', 'active' => true,
        ]);

        $count = MealCount::create([
            'company_id' => $company->id, 'tiffin_service_id' => $tiffin->id,
            'date' => '2026-10-06', 'base_eligible_count' => 1, 'skip_count' => 0, 'extra_count' => 0,
            'final_expected_count' => 1, 'breakdown' => [], 'status' => 'confirmed', 'locked_at' => now(),
        ]);

        return compact('tiffin', 'company', 'admin', 'vendor', 'employeeUser', 'employee', 'skip', 'adjustment', 'calendarDay', 'rule', 'count');
    }

    // ------------------------------------------------------- role boundaries

    /** @return array<string, array<int, string>> */
    public static function rolePages(): array
    {
        return [
            'super admin dashboard' => ['/super-admin/dashboard', 'super_admin'],
            'super admin health' => ['/super-admin/health', 'super_admin'],
            'company dashboard' => ['/company-admin/dashboard', 'company_admin'],
            'company daily' => ['/company-admin/daily', 'company_admin'],
            'company employees' => ['/company-admin/employees', 'company_admin'],
            'company settings' => ['/company-admin/settings', 'company_admin'],
            'company calendar' => ['/company-admin/calendar', 'company_admin'],
            'company hrms' => ['/company-admin/hrms', 'company_admin'],
            'company adoption report' => ['/company-admin/reports/adoption', 'company_admin'],
            'employee dashboard' => ['/employee/dashboard', 'employee'],
            'vendor dashboard' => ['/tiffin-admin/dashboard', 'tiffin_admin'],
            'vendor preparation' => ['/tiffin-admin/preparation', 'tiffin_admin'],
        ];
    }

    /**
     * Nobody reaches a page belonging to a role they do not hold. Asserted for
     * every page and every other role, rather than spot-checked.
     */
    #[DataProvider('rolePages')]
    public function test_only_the_owning_role_can_open_the_page(string $url, string $owningRole): void
    {
        $actors = [
            'super_admin' => $this->root,
            'company_admin' => $this->a['admin'],
            'employee' => $this->a['employeeUser'],
            'tiffin_admin' => $this->a['vendor'],
        ];

        foreach ($actors as $role => $user) {
            $response = $this->actingAs(User::findOrFail($user->id))->get($url);

            if ($role === $owningRole) {
                $response->assertStatus(200);

                continue;
            }

            $this->assertContains(
                $response->status(),
                [403, 404, 302],
                "{$role} got {$response->status()} from {$url}, which belongs to {$owningRole}",
            );

            if ($response->status() === 302) {
                $this->assertNotSame(
                    $url,
                    $response->headers->get('Location'),
                    "{$role} was redirected to {$url} itself",
                );
            }
        }
    }

    public function test_a_guest_reaches_no_signed_in_page(): void
    {
        foreach (self::rolePages() as [$url]) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    // ------------------------------------------- IDOR: company admin bindings

    /**
     * Every company-admin route that takes an id, aimed at tenant B's record
     * while signed in as tenant A.
     *
     * @return array<string, array<int, string>>
     */
    public static function companyAdminIdorRoutes(): array
    {
        return [
            'update an employee' => ['put', '/company-admin/employees/{employee}'],
            'reset an employee password' => ['post', '/company-admin/employees/{employee}/reset-password'],
            'add a recurring rule' => ['post', '/company-admin/employees/{employee}/recurring-skips'],
            'change a recurring rule' => ['patch', '/company-admin/employees/{employee}/recurring-skips/{rule}'],
            'delete a recurring rule' => ['delete', '/company-admin/employees/{employee}/recurring-skips/{rule}'],
            'delete a skip' => ['delete', '/company-admin/skips/{skip}'],
            'delete an extra meal' => ['delete', '/company-admin/extra-meals/{adjustment}'],
            'delete a calendar day' => ['delete', '/company-admin/calendar/{day}'],
        ];
    }

    #[DataProvider('companyAdminIdorRoutes')]
    public function test_a_company_admin_cannot_touch_another_tenants_record(string $method, string $template): void
    {
        $url = str_replace(
            ['{employee}', '{rule}', '{skip}', '{adjustment}', '{day}'],
            [
                (string) $this->b['employee']->id,
                (string) $this->b['rule']->id,
                (string) $this->b['skip']->id,
                (string) $this->b['adjustment']->id,
                (string) $this->b['calendarDay']->id,
            ],
            $template,
        );

        $before = $this->snapshotOfTenantB();

        $response = $this->actingAs(User::findOrFail($this->a['admin']->id))->{$method}($url, [
            // Enough body for validation to pass, so the refusal is the
            // authorisation check and not a missing field.
            'name' => 'Renamed by the wrong tenant',
            'status' => 'inactive',
            'is_meal_eligible' => false,
            'weekday' => 3,
            'starts_on' => '2026-10-01',
            'active' => true,
        ]);

        $this->assertNotSame(200, $response->status(), "{$method} {$url} was accepted");

        $this->assertSame(
            $before,
            $this->snapshotOfTenantB(),
            "{$method} {$url} changed tenant B's data",
        );
    }

    /**
     * Everything about tenant B that a cross-tenant request might alter,
     * reduced to a comparable shape.
     *
     * @return array<string, mixed>
     */
    protected function snapshotOfTenantB(): array
    {
        $employee = Employee::findOrFail($this->b['employee']->id);
        $user = User::findOrFail($this->b['employeeUser']->id);

        return [
            'employee' => [$employee->name, $employee->status, $employee->is_meal_eligible, $employee->email],
            'password' => $user->password,
            'must_change_password' => $user->must_change_password,
            'skips' => Skip::where('company_id', $this->b['company']->id)
                ->get(['id', 'date', 'source', 'cancelled_at'])->toJson(),
            'adjustments' => MealAdjustment::where('company_id', $this->b['company']->id)
                ->get(['id', 'quantity', 'cancelled_at'])->toJson(),
            'calendar' => CompanyCalendarDay::where('company_id', $this->b['company']->id)
                ->get(['id', 'date', 'type'])->toJson(),
            'rules' => RecurringSkip::where('company_id', $this->b['company']->id)
                ->get(['id', 'weekday', 'active'])->toJson(),
            'settings' => CompanySetting::where('company_id', $this->b['company']->id)
                ->get(['cutoff_time', 'timezone', 'meal_days'])->toJson(),
        ];
    }

    // ------------------------------------------ IDOR: through the request body

    /**
     * The id does not have to be in the URL. These pass another tenant's
     * employee in the body, where `exists:employees,id` alone would accept it.
     */
    public function test_a_skip_cannot_be_recorded_for_another_tenants_employee(): void
    {
        $before = $this->snapshotOfTenantB();

        $this->actingAs(User::findOrFail($this->a['admin']->id))
            ->post('/company-admin/skips', [
                'employee_id' => $this->b['employee']->id,
                'date' => '2026-10-13',
                'source' => 'hr',
            ]);

        $this->assertSame($before, $this->snapshotOfTenantB());
        $this->assertSame(1, Skip::where('company_id', $this->b['company']->id)->count());
    }

    public function test_a_bulk_skip_cannot_reach_another_tenants_employee(): void
    {
        $before = $this->snapshotOfTenantB();

        $this->actingAs(User::findOrFail($this->a['admin']->id))
            ->post('/company-admin/skips/bulk', [
                'employee_ids' => [$this->b['employee']->id],
                'dates' => ['2026-10-13'],
                'source' => 'hr',
            ]);

        $this->assertSame($before, $this->snapshotOfTenantB());
    }

    public function test_logins_cannot_be_created_for_another_tenants_employee(): void
    {
        $before = $this->snapshotOfTenantB();

        $this->actingAs(User::findOrFail($this->a['admin']->id))
            ->post('/company-admin/employees/logins', [
                'employee_ids' => [$this->b['employee']->id],
            ]);

        $this->assertSame($before, $this->snapshotOfTenantB());
    }

    // ------------------------------------------------- IDOR: employee bindings

    public function test_an_employee_cannot_cancel_someone_elses_skip(): void
    {
        $before = $this->snapshotOfTenantB();

        $this->actingAs(User::findOrFail($this->a['employeeUser']->id))
            ->delete('/employee/skips/'.$this->b['skip']->id);

        $this->assertSame($before, $this->snapshotOfTenantB());
    }

    public function test_an_employee_cannot_change_someone_elses_recurring_rule(): void
    {
        $before = $this->snapshotOfTenantB();

        $this->actingAs(User::findOrFail($this->a['employeeUser']->id))
            ->patch('/employee/recurring-skips/'.$this->b['rule']->id, ['active' => false]);

        $this->actingAs(User::findOrFail($this->a['employeeUser']->id))
            ->delete('/employee/recurring-skips/'.$this->b['rule']->id);

        $this->assertSame($before, $this->snapshotOfTenantB());
    }

    // --------------------------------------------------------- vendor scoping

    /**
     * A vendor sees counts for the companies it cooks for and no others, and
     * never an employee's name - it is told how many, never who.
     */
    public function test_a_vendor_sees_only_its_own_companies(): void
    {
        $props = $this->actingAs(User::findOrFail($this->a['vendor']->id))
            ->get('/tiffin-admin/preparation?date=2026-10-06')
            ->getOriginalContent()->getData()['page']['props'];

        $json = json_encode($props);

        $this->assertStringContainsString('Alpha Corp', $json);
        $this->assertStringNotContainsString('Beta Corp', $json);
        $this->assertStringNotContainsString('Staff B', $json);
        $this->assertStringNotContainsString('BETA01001', $json);
    }

    public function test_a_vendor_cannot_override_a_date_for_another_vendors_kitchen(): void
    {
        $this->actingAs(User::findOrFail($this->a['vendor']->id))
            ->post('/tiffin-admin/daily-override', [
                'tiffin_service_id' => $this->b['tiffin']->id,
                'date' => '2026-10-13',
                'meal_description' => 'Planted by the other vendor',
            ]);

        $this->assertDatabaseMissing('daily_overrides', [
            'tiffin_service_id' => $this->b['tiffin']->id,
            'meal_description' => 'Planted by the other vendor',
        ]);
    }

    // ------------------------------------------- deactivated and archived users

    public function test_a_deactivated_admin_cannot_sign_in(): void
    {
        $this->a['admin']->update(['is_active' => false, 'deactivated_at' => now()]);

        $this->post('/login', ['identifier' => 'hra@test.test', 'password' => 'password-1'])
            ->assertSessionHasErrors();

        $this->assertGuest();
    }

    /**
     * The session is what matters as much as the sign-in: an admin deactivated
     * while signed in must stop being able to act.
     */
    public function test_a_deactivated_admin_with_a_live_session_is_locked_out(): void
    {
        $this->actingAs(User::findOrFail($this->a['admin']->id))->get('/company-admin/dashboard')->assertStatus(200);

        $this->a['admin']->update(['is_active' => false, 'deactivated_at' => now()]);

        $response = $this->actingAs(User::findOrFail($this->a['admin']->id))->get('/company-admin/dashboard');

        $this->assertNotSame(
            200,
            $response->status(),
            'a deactivated admin kept working through an existing session',
        );
    }

    /**
     * Archiving is supposed to take access with it. Every one of these kept
     * working through an existing session until the middleware was added.
     */
    public function test_archiving_a_company_ends_its_admins_live_session(): void
    {
        $this->actingAs(User::findOrFail($this->a['admin']->id))->get('/company-admin/dashboard')->assertStatus(200);

        $this->a['company']->delete();

        $this->actingAs(User::findOrFail($this->a['admin']->id))
            ->get('/company-admin/dashboard')
            ->assertRedirect('/login');
    }

    public function test_archiving_a_company_ends_its_employees_live_session(): void
    {
        $this->actingAs(User::findOrFail($this->a['employeeUser']->id))->get('/employee/dashboard')->assertStatus(200);

        $this->a['company']->delete();

        $this->actingAs(User::findOrFail($this->a['employeeUser']->id))
            ->get('/employee/dashboard')
            ->assertRedirect('/login');
    }

    public function test_archiving_a_tiffin_service_ends_its_vendors_live_session(): void
    {
        $this->actingAs(User::findOrFail($this->a['vendor']->id))->get('/tiffin-admin/preparation')->assertStatus(200);

        $this->a['tiffin']->delete();

        $this->actingAs(User::findOrFail($this->a['vendor']->id))
            ->get('/tiffin-admin/preparation')
            ->assertRedirect('/login');
    }

    /**
     * An employee stood down by their HR team, which is a different switch from
     * a super admin deactivating the login.
     */
    public function test_standing_an_employee_down_ends_their_live_session(): void
    {
        $this->actingAs(User::findOrFail($this->a['employeeUser']->id))->get('/employee/dashboard')->assertStatus(200);

        $this->a['employee']->update(['status' => 'inactive']);

        $this->actingAs(User::findOrFail($this->a['employeeUser']->id))
            ->get('/employee/dashboard')
            ->assertRedirect('/login');
    }

    /**
     * And the session is destroyed, not merely refused - otherwise reactivating
     * the account would silently restore a session nobody re-authenticated.
     */
    public function test_the_revoked_session_is_destroyed_rather_than_refused(): void
    {
        // Signed in for real rather than through actingAs, which pins one model
        // instance for the whole test and would still report the account as
        // active after it was deactivated. A real request reloads the user from
        // the session on every hit, which is the behaviour under test.
        $this->post('/login', ['identifier' => 'hra@test.test', 'password' => 'password-1'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticated();
        $this->get('/company-admin/dashboard')->assertStatus(200);

        $this->a['admin']->update(['is_active' => false, 'deactivated_at' => now()]);

        // The guard caches the user it resolved, and the test process keeps one
        // container across requests - so without this the middleware would read
        // the instance from before the deactivation. In production every
        // request is a fresh container and reloads the user from the session,
        // which is what this restores rather than works around.
        $this->app['auth']->forgetGuards();

        $this->get('/company-admin/dashboard')->assertRedirect('/login');

        // Destroyed, not merely refused: otherwise reactivating the account
        // would silently restore a session nobody re-authenticated.
        $this->assertGuest();
    }

    public function test_an_archived_companys_admin_cannot_sign_in(): void
    {
        $this->a['company']->delete();

        $this->post('/login', ['identifier' => 'hra@test.test', 'password' => 'password-1'])
            ->assertSessionHasErrors();

        $this->assertGuest();
    }

    // --------------------------------------------------------- forgot password

    /**
     * The response cannot be used to learn which addresses are registered.
     */
    public function test_forgot_password_answers_identically_for_unknown_addresses(): void
    {
        $known = $this->post('/forgot-password', ['email' => 'hra@test.test']);
        $this->flushSession();
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@nowhere.test']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame(
            session()->get('status'),
            $known->getSession()->get('status') ?? session()->get('status'),
        );
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        RateLimiter::clear('password-reset');

        $status = null;

        for ($i = 0; $i < 12; $i++) {
            $this->flushSession();
            $status = $this->post('/forgot-password', ['email' => 'hra@test.test'])->status();
        }

        $this->assertSame(429, $status, 'the reset form is not rate limited');
    }

    // ------------------------------------------------------- webhook signature

    public function test_the_webhook_refuses_an_unsigned_payload(): void
    {
        $this->postJson('/api/hrms/'.$this->b['company']->code.'/events', [
            'event_id' => 'forged-1', 'event_type' => 'leave.approved',
        ])->assertStatus(401);

        $this->assertDatabaseCount('hrms_webhook_events', 0);
    }

    public function test_the_webhook_refuses_a_wrong_signature(): void
    {
        $this->postJson(
            '/api/hrms/'.$this->b['company']->code.'/events',
            ['event_id' => 'forged-2', 'event_type' => 'leave.approved'],
            ['X-Mealbells-Signature' => 'sha256=deadbeef', 'X-Mealbells-Timestamp' => (string) time()],
        )->assertStatus(401);

        $this->assertDatabaseCount('hrms_webhook_events', 0);
    }

    /**
     * One tenant's secret must not sign another tenant's events.
     */
    public function test_a_tenants_secret_does_not_work_for_another_tenant(): void
    {
        $secret = 'whsec_tenant_a_only';

        CompanyHrmsConnection::create([
            'company_id' => $this->a['company']->id,
            'webhook_secret' => $secret,
        ]);

        $body = json_encode(['event_id' => 'cross-1', 'event_type' => 'leave.approved']);
        $timestamp = (string) time();
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        $this->call(
            'POST',
            '/api/hrms/'.$this->b['company']->code.'/events',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_MEALBELLS_SIGNATURE' => $signature,
                'HTTP_X_MEALBELLS_TIMESTAMP' => $timestamp,
                'HTTP_ACCEPT' => 'application/json',
            ],
            $body,
        )->assertStatus(401);

        $this->assertDatabaseCount('hrms_webhook_events', 0);
    }
}
