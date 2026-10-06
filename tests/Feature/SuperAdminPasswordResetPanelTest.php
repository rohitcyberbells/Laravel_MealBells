<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The reset flashed a temporary password that no render ever read, and the
 * dashboard offered no way to trigger a reset in the first place, so the only
 * copy of the new password was lost the moment it was generated.
 */
class SuperAdminPasswordResetPanelTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected Company $company;

    protected TiffinService $tiffin;

    protected User $companyAdmin;

    protected User $tiffinAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test',
            'password' => bcrypt('password'), 'role' => 'super_admin',
        ]);

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        $this->tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '9876543211']);

        $this->companyAdmin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('oldpass'),
            'role' => 'company_admin', 'company_id' => $this->company->id, 'must_change_password' => false,
        ]);

        $this->tiffinAdmin = User::create([
            'name' => 'Royal Chef', 'email' => 'chef@royal.test', 'password' => bcrypt('oldpass'),
            'role' => 'tiffin_admin', 'tiffin_service_id' => $this->tiffin->id, 'must_change_password' => false,
        ]);
    }

    protected function reset(User $user)
    {
        return $this->actingAs($this->superAdmin)
            ->from('/super-admin/dashboard')
            ->post("/super-admin/users/{$user->id}/reset-password");
    }

    /** @return array<string, mixed> */
    protected function dashboardProps(): array
    {
        return $this->actingAs($this->superAdmin)->get('/super-admin/dashboard')
            ->getOriginalContent()->getData()['page']['props'];
    }

    public function test_the_temporary_password_reaches_the_page(): void
    {
        $this->reset($this->companyAdmin)->assertRedirect('/super-admin/dashboard');

        $this->actingAs($this->superAdmin)->get('/super-admin/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Dashboard')
                // Exactly the prop the panel renders.
                ->whereNot('temporary_password', null)
                ->where('reset_for.name', 'Acme HR')
                ->where('reset_for.email', 'hr@acme.test')
                ->etc()
            );
    }

    public function test_the_password_shown_is_the_one_that_now_works(): void
    {
        $this->reset($this->companyAdmin);

        $password = $this->dashboardProps()['temporary_password'];

        $this->assertNotEmpty($password);
        $this->assertTrue(Auth::validate(['email' => 'hr@acme.test', 'password' => $password]));
        $this->assertFalse(Auth::validate(['email' => 'hr@acme.test', 'password' => 'oldpass']));
    }

    public function test_the_panel_is_gone_on_a_reload(): void
    {
        $this->reset($this->companyAdmin);

        $this->assertNotNull($this->dashboardProps()['temporary_password']);

        // Flash data, so the second render no longer carries it.
        $this->assertNull($this->dashboardProps()['temporary_password']);
        $this->assertNull($this->dashboardProps()['reset_for']);
    }

    public function test_the_password_is_never_stored_in_plain_text(): void
    {
        $this->reset($this->companyAdmin);

        $password = $this->dashboardProps()['temporary_password'];

        $row = (array) DB::table('users')->where('email', 'hr@acme.test')->first();

        $this->assertNotEquals($password, $row['password']);
        $this->assertTrue(password_verify($password, $row['password']));
        $this->assertStringNotContainsString($password, json_encode($row));
    }

    public function test_the_dashboard_lists_the_admins_each_reset_button_needs(): void
    {
        $props = $this->dashboardProps();

        $companyAdmins = collect($props['companies'])->firstWhere('id', $this->company->id)['admins'];
        $this->assertCount(1, $companyAdmins);
        $this->assertEquals($this->companyAdmin->id, $companyAdmins[0]['id']);
        $this->assertEquals('hr@acme.test', $companyAdmins[0]['email']);

        $tiffinAdmins = collect($props['tiffinServices'])->firstWhere('id', $this->tiffin->id)['admins'];
        $this->assertCount(1, $tiffinAdmins);
        $this->assertEquals($this->tiffinAdmin->id, $tiffinAdmins[0]['id']);
    }

    /**
     * An employee's password is their own company admin's to reset, and listing
     * every employee would bury the few accounts this screen is for.
     */
    public function test_employees_are_not_listed_as_resettable_admins(): void
    {
        $employeeUser = User::create([
            'name' => 'Alice', 'email' => 'alice@acme.test', 'password' => bcrypt('pass'),
            'role' => 'employee', 'company_id' => $this->company->id, 'login_code' => 'ACME001',
        ]);

        $admins = collect($this->dashboardProps()['companies'])
            ->firstWhere('id', $this->company->id)['admins'];

        $this->assertNotContains($employeeUser->id, collect($admins)->pluck('id')->all());
    }

    public function test_a_reset_forces_a_password_change_on_next_sign_in(): void
    {
        $this->assertFalse($this->companyAdmin->must_change_password);

        $this->reset($this->companyAdmin);

        $this->assertTrue($this->companyAdmin->fresh()->must_change_password);
    }

    /**
     * The prop the page renders and the prop the controller sends have to agree;
     * a rename on either side would otherwise only surface when someone
     * actually reset a password - which is exactly how this broke.
     */
    public function test_the_page_reads_the_props_the_controller_sends(): void
    {
        $dashboard = file_get_contents(resource_path('js/Pages/SuperAdmin/Dashboard.vue'));

        $this->assertStringContainsString('temporary_password', $dashboard);
        $this->assertStringContainsString('reset_for', $dashboard);
        $this->assertStringContainsString('/reset-password', $dashboard);

        $props = $this->dashboardProps();
        $this->assertArrayHasKey('temporary_password', $props);
        $this->assertArrayHasKey('reset_for', $props);
    }
}
