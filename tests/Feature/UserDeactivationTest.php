<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Turning an account's access off.
 *
 * An employee could already be stood down through employees.status, but an
 * admin could not: revoking their access meant deleting the row, which
 * cascades, or quietly changing their password. Neither is reversible and
 * neither leaves a record.
 */
class UserDeactivationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $root;

    protected User $otherRoot;

    protected User $companyAdmin;

    protected User $tiffinAdmin;

    protected User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);
        RateLimiter::clear('login');

        $tiffin = TiffinService::create(['name' => 'Tiffin Co', 'address' => 'A', 'contact_phone' => '9876543210']);
        $this->company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->root = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test',
            'password' => bcrypt('password-1'), 'role' => 'super_admin',
        ]);

        $this->otherRoot = User::create([
            'name' => 'Second Root', 'email' => 'root2@mealbells.test',
            'password' => bcrypt('password-1'), 'role' => 'super_admin',
        ]);

        $this->companyAdmin = User::create([
            'name' => 'Alpha HR', 'email' => 'hr@alpha.test',
            'password' => bcrypt('password-1'), 'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        $this->tiffinAdmin = User::create([
            'name' => 'Chef', 'email' => 'chef@tiffin.test',
            'password' => bcrypt('password-1'), 'role' => 'tiffin_admin', 'tiffin_service_id' => $tiffin->id,
        ]);

        $this->employeeUser = User::create([
            'name' => 'Alice', 'email' => 'alice@alpha.test', 'password' => bcrypt('password-1'),
            'role' => 'employee', 'company_id' => $this->company->id, 'login_code' => 'EMP101',
        ]);

        Employee::create([
            'company_id' => $this->company->id, 'user_id' => $this->employeeUser->id,
            'employee_code' => 'EMP101', 'email' => 'alice@alpha.test', 'name' => 'Alice',
            'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    protected function setActive(User $target, bool $active, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->root)->from('/super-admin/dashboard')
            ->post("/super-admin/users/{$target->id}/active", ['is_active' => $active]);
    }

    /**
     * actingAs leaves that admin signed in, and the 'guest' middleware then
     * redirects any later /login attempt - which looks exactly like a
     * successful sign-in. So every test that attempts a login signs out first.
     */
    protected function signOut(): void
    {
        $this->post('/logout');
        $this->flushSession();
    }

    public function test_every_account_starts_active(): void
    {
        // Read back from the database: the column default is applied there, not
        // on the instance returned by create().
        foreach ([$this->root, $this->companyAdmin, $this->tiffinAdmin, $this->employeeUser] as $user) {
            $this->assertTrue($user->fresh()->is_active, "{$user->email} did not start active");
        }
    }

    /** @return array<int, array<int, string>> */
    public static function deactivatableRoles(): array
    {
        return [['companyAdmin'], ['tiffinAdmin'], ['employeeUser'], ['otherRoot']];
    }

    #[DataProvider('deactivatableRoles')]
    public function test_a_deactivated_account_cannot_sign_in(string $which): void
    {
        $target = $this->{$which};

        $this->setActive($target, false)->assertSessionHasNoErrors();

        $this->assertFalse($target->fresh()->is_active);

        $this->signOut();
        $this->post('/login', ['identifier' => $target->email, 'password' => 'password-1'])
            ->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_reactivating_restores_access(): void
    {
        $this->setActive($this->companyAdmin, false);
        $this->setActive($this->companyAdmin, true)->assertSessionHasNoErrors();

        $fresh = $this->companyAdmin->fresh();

        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->deactivated_at);
        $this->assertNull($fresh->deactivated_by);

        $this->signOut();
        $this->post('/login', ['identifier' => 'hr@alpha.test', 'password' => 'password-1'])
            ->assertRedirect('/company-admin/dashboard');
    }

    public function test_the_decision_is_recorded(): void
    {
        $this->setActive($this->companyAdmin, false);

        $fresh = $this->companyAdmin->fresh();

        $this->assertNotNull($fresh->deactivated_at);
        $this->assertEquals($this->root->id, $fresh->deactivated_by);
    }

    /**
     * Locking yourself out of the only account that can unlock accounts is a
     * one-way door.
     */
    public function test_you_cannot_deactivate_your_own_account(): void
    {
        $this->setActive($this->root, false, $this->root)
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($this->root->fresh()->is_active);
    }

    public function test_the_page_does_not_offer_the_button_for_yourself(): void
    {
        $page = file_get_contents(resource_path('js/Pages/SuperAdmin/Dashboard.vue'));

        $this->assertStringContainsString('admin.id !== user?.id', $page);
        $this->assertStringContainsString('setActive(admin)', $page);
    }

    /**
     * The point of deactivating rather than deleting: nothing they created is
     * removed.
     */
    public function test_deactivating_removes_nothing_they_created(): void
    {
        $skip = Skip::create([
            'company_id' => $this->company->id,
            'employee_id' => Employee::sole()->id,
            'date' => '2026-10-08', 'source' => 'hr',
            'created_by' => $this->companyAdmin->id,
        ]);

        $this->setActive($this->companyAdmin, false);

        $this->assertDatabaseHas('skips', ['id' => $skip->id, 'created_by' => $this->companyAdmin->id]);
        $this->assertDatabaseHas('users', ['id' => $this->companyAdmin->id]);
    }

    /**
     * The two switches are owned by different people: HR stands an employee
     * down on their employee record, a super admin deactivates the account.
     * Either one alone must refuse the sign-in.
     */
    public function test_an_employee_stood_down_by_hr_is_still_refused(): void
    {
        Employee::where('user_id', $this->employeeUser->id)->update(['status' => 'inactive']);

        $this->assertTrue($this->employeeUser->fresh()->is_active, 'the account itself is untouched');

        $this->post('/login', ['identifier' => 'alice@alpha.test', 'password' => 'password-1'])
            ->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_a_deactivated_employee_cannot_sign_in_by_code_either(): void
    {
        $this->setActive($this->employeeUser, false);

        $this->signOut();
        $this->post('/login', [
            'identifier' => 'EMP101', 'company_code' => 'ALPHA1', 'password' => 'password-1',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }

    /**
     * Saying "deactivated" on the email path would confirm the account exists.
     */
    public function test_the_refusal_looks_like_a_wrong_address(): void
    {
        $this->setActive($this->companyAdmin, false);
        $this->signOut();

        $this->post('/login', ['identifier' => 'hr@alpha.test', 'password' => 'password-1'])
            ->assertSessionHasErrors('identifier');
        $deactivated = session('errors')->get('identifier');

        $this->flushSession();

        $this->post('/login', ['identifier' => 'nobody@alpha.test', 'password' => 'password-1'])
            ->assertSessionHasErrors('identifier');
        $unknown = session('errors')->get('identifier');

        $this->assertEquals($unknown, $deactivated, 'a deactivated account is distinguishable from an unknown one');
        $this->assertStringContainsString('do not match', $deactivated[0]);
    }

    /**
     * No reset link either - they could not use the new password anyway.
     */
    public function test_a_deactivated_account_gets_no_reset_link(): void
    {
        Notification::fake();
        RateLimiter::clear('password-reset');

        $this->setActive($this->companyAdmin, false);
        $this->signOut();

        $this->post('/forgot-password', ['email' => 'hr@alpha.test']);

        Notification::assertNothingSent();
    }

    // ---------------------------------------------------------------- who may

    public function test_a_company_admin_cannot_deactivate_anyone(): void
    {
        $this->setActive($this->tiffinAdmin, false, $this->companyAdmin)->assertStatus(403);

        $this->assertTrue($this->tiffinAdmin->fresh()->is_active);
    }

    public function test_an_employee_cannot_deactivate_anyone(): void
    {
        $this->setActive($this->companyAdmin, false, $this->employeeUser)->assertStatus(403);

        $this->assertTrue($this->companyAdmin->fresh()->is_active);
    }

    public function test_a_guest_cannot_deactivate_anyone(): void
    {
        $this->post("/super-admin/users/{$this->companyAdmin->id}/active", ['is_active' => false])
            ->assertRedirect('/login');

        $this->assertTrue($this->companyAdmin->fresh()->is_active);
    }

    public function test_the_dashboard_reports_the_flag(): void
    {
        $this->setActive($this->companyAdmin, false);

        $props = $this->actingAs($this->root)->get('/super-admin/dashboard')
            ->getOriginalContent()->getData()['page']['props'];

        $admins = collect($props['companies'])->firstWhere('id', $this->company->id)['admins'];
        $row = collect($admins)->firstWhere('id', $this->companyAdmin->id);

        $this->assertFalse((bool) $row['is_active']);
        $this->assertNotNull($row['deactivated_at']);
    }
}
