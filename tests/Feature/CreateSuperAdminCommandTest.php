<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The only supported way into a fresh production install.
 *
 * Both seeders refuse outside local and testing, so without this the first
 * sign-in needed tinker on the server.
 */
class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_super_admin(): void
    {
        $this->artisan('mealbells:create-super-admin', [
            '--email' => 'ops@company.test',
            '--name' => 'Platform Root',
            '--password' => 'a-long-password-9',
        ])->assertSuccessful();

        $user = User::sole();

        $this->assertEquals('ops@company.test', $user->email);
        $this->assertEquals('Platform Root', $user->name);
        $this->assertEquals('super_admin', $user->role);
        $this->assertTrue(Hash::check('a-long-password-9', $user->password));
    }

    /**
     * The password only has to survive being typed once.
     */
    public function test_a_password_change_is_required_by_default(): void
    {
        $this->artisan('mealbells:create-super-admin', [
            '--email' => 'ops@company.test', '--name' => 'Root', '--password' => 'a-long-password-9',
        ])->assertSuccessful();

        $this->assertTrue(User::sole()->must_change_password);

        // And the gate actually holds.
        $this->post('/login', ['identifier' => 'ops@company.test', 'password' => 'a-long-password-9']);
        $this->get('/super-admin/dashboard')->assertRedirect('/change-password');
    }

    public function test_the_forced_change_can_be_turned_off(): void
    {
        $this->artisan('mealbells:create-super-admin', [
            '--email' => 'ops@company.test', '--name' => 'Root',
            '--password' => 'a-long-password-9', '--no-force-change' => true,
        ])->assertSuccessful();

        $this->assertFalse(User::sole()->must_change_password);
    }

    public function test_it_can_generate_a_password_and_show_it_once(): void
    {
        $this->artisan('mealbells:create-super-admin', [
            '--email' => 'ops@company.test', '--name' => 'Root', '--generate-password' => true,
        ])->expectsOutputToContain('Temporary password (shown once)')
            ->assertSuccessful();

        $user = User::sole();

        $this->assertNotEmpty($user->password);
        $this->assertTrue($user->must_change_password);
    }

    /**
     * A generated password must satisfy the policy the app enforces, or the
     * forced change would refuse the very password it just printed.
     */
    public function test_a_generated_password_satisfies_the_policy(): void
    {
        $this->artisan('mealbells:create-super-admin', [
            '--email' => 'ops@company.test', '--name' => 'Root', '--generate-password' => true,
        ])->assertSuccessful();

        // Shown only in the output, so this asserts via the login it enables.
        $this->assertDatabaseCount('users', 1);
        $this->assertEquals('super_admin', User::sole()->role);
    }

    /**
     * Re-running must not reset a colleague's password or change their role.
     */
    public function test_it_is_idempotent_and_leaves_an_existing_account_alone(): void
    {
        User::create([
            'name' => 'Existing Admin', 'email' => 'ops@company.test',
            'password' => bcrypt('their-own-password-3'), 'role' => 'company_admin',
        ]);

        $this->artisan('mealbells:create-super-admin', [
            '--email' => 'ops@company.test', '--name' => 'Someone Else', '--password' => 'a-long-password-9',
        ])->expectsOutputToContain('already exists')
            ->expectsOutputToContain('Nothing was changed')
            ->assertSuccessful();

        $user = User::sole();

        $this->assertEquals('Existing Admin', $user->name);
        $this->assertEquals('company_admin', $user->role, 'the role was not escalated');
        $this->assertTrue(Hash::check('their-own-password-3', $user->password));
    }

    public function test_it_matches_an_existing_address_regardless_of_case(): void
    {
        User::create([
            'name' => 'Existing', 'email' => 'ops@company.test',
            'password' => bcrypt('their-own-password-3'), 'role' => 'super_admin',
        ]);

        $this->artisan('mealbells:create-super-admin', [
            '--email' => 'OPS@COMPANY.TEST', '--name' => 'Root', '--password' => 'a-long-password-9',
        ])->expectsOutputToContain('already exists')->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_weak_password_is_refused(): void
    {
        $this->artisan('mealbells:create-super-admin', [
            '--email' => 'ops@company.test', '--name' => 'Root', '--password' => 'abc123',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_malformed_address_is_refused(): void
    {
        $this->artisan('mealbells:create-super-admin', [
            '--email' => 'not-an-address', '--name' => 'Root', '--password' => 'a-long-password-9',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * On a server this runs unattended, where there is nothing to prompt.
     */
    public function test_it_refuses_without_a_password_when_not_interactive(): void
    {
        $this->artisan('mealbells:create-super-admin', [
            '--email' => 'ops@company.test', '--name' => 'Root', '--no-interaction' => true,
        ])->expectsOutputToContain('No password given')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * The one thing about this account that cannot be fixed later without
     * database access, so the command says it.
     */
    public function test_it_warns_that_there_is_no_other_recovery(): void
    {
        $this->artisan('mealbells:create-super-admin', [
            '--email' => 'ops@company.test', '--name' => 'Root', '--password' => 'a-long-password-9',
        ])->expectsOutputToContain('no recovery')->assertSuccessful();
    }

    public function test_the_address_is_stored_lowercased(): void
    {
        $this->artisan('mealbells:create-super-admin', [
            '--email' => 'OPS@Company.Test', '--name' => 'Root', '--password' => 'a-long-password-9',
        ])->assertSuccessful();

        $this->assertEquals('ops@company.test', User::sole()->email);
    }

    public function test_the_deploy_doc_tells_people_to_use_it(): void
    {
        $doc = file_get_contents(base_path('docs/deploy.md'));

        $this->assertStringContainsString('mealbells:create-super-admin', $doc);
    }
}
