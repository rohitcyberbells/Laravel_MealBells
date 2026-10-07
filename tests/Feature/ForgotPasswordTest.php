<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\PasswordResetLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Forgotten passwords.
 *
 * Two rules shape every test here: the response never reveals whether an
 * account exists, and an employee whose address is a stand-in is never mailed
 * because that mail could not arrive.
 */
class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected const GENERIC = 'If that address has an account, a reset link is on its way. Check your inbox, including spam.';

    protected Company $company;

    protected User $admin;

    protected User $employeeWithEmail;

    protected User $employeeWithPlaceholder;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);
        RateLimiter::clear('password-reset');

        $this->company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->admin = User::create([
            'name' => 'Alpha HR', 'email' => 'hr@alpha.test', 'password' => bcrypt('old-password-1'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        $this->employeeWithEmail = User::create([
            'name' => 'Alice', 'email' => 'alice@alpha.test', 'password' => bcrypt('old-password-1'),
            'role' => 'employee', 'company_id' => $this->company->id, 'login_code' => 'EMP101',
            'must_change_password' => true,
        ]);

        Employee::create([
            'company_id' => $this->company->id, 'user_id' => $this->employeeWithEmail->id,
            'employee_code' => 'EMP101', 'email' => 'alice@alpha.test', 'name' => 'Alice',
            'status' => 'active', 'is_meal_eligible' => true,
        ]);

        // The stand-in address CreateEmployeeLogins gives an employee with none.
        $this->employeeWithPlaceholder = User::create([
            'name' => 'Bob', 'email' => 'emp102@alpha1.local', 'password' => bcrypt('old-password-1'),
            'role' => 'employee', 'company_id' => $this->company->id, 'login_code' => 'EMP102',
        ]);

        Employee::create([
            'company_id' => $this->company->id, 'user_id' => $this->employeeWithPlaceholder->id,
            'employee_code' => 'EMP102', 'email' => null, 'name' => 'Bob',
            'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    protected function request(string $email)
    {
        return $this->from('/forgot-password')->post('/forgot-password', ['email' => $email]);
    }

    // ------------------------------------------------------------- the page

    public function test_the_form_is_reachable_without_signing_in(): void
    {
        $this->get('/forgot-password')->assertStatus(200);
    }

    public function test_the_sign_in_page_links_to_it(): void
    {
        $this->assertStringContainsString(
            '/forgot-password',
            file_get_contents(resource_path('js/Pages/Auth/Login.vue')),
        );
    }

    // ------------------------------------------------------------ the happy path

    public function test_an_admin_with_a_real_address_is_sent_a_link(): void
    {
        Notification::fake();

        $this->request('hr@alpha.test')->assertSessionHas('message', self::GENERIC);

        Notification::assertSentTo($this->admin, PasswordResetLinkNotification::class);
    }

    public function test_an_employee_with_a_real_address_is_sent_a_link(): void
    {
        Notification::fake();

        $this->request('alice@alpha.test');

        Notification::assertSentTo($this->employeeWithEmail, PasswordResetLinkNotification::class);
    }

    public function test_the_whole_flow_changes_the_password(): void
    {
        $token = Password::broker()->createToken($this->employeeWithEmail);

        $this->get("/reset-password/{$token}?email=alice@alpha.test")->assertStatus(200);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'alice@alpha.test',
            'password' => 'brand-new-password-4',
            'password_confirmation' => 'brand-new-password-4',
        ])->assertRedirect(route('login'));

        $fresh = $this->employeeWithEmail->fresh();

        $this->assertTrue(Hash::check('brand-new-password-4', $fresh->password));
        $this->assertFalse(Hash::check('old-password-1', $fresh->password));
    }

    /**
     * They have just chosen it themselves, so the forced-change gate has
     * nothing left to ask for.
     */
    public function test_a_reset_clears_the_forced_change_flag(): void
    {
        $this->assertTrue($this->employeeWithEmail->must_change_password);

        $token = Password::broker()->createToken($this->employeeWithEmail);

        $this->post('/reset-password', [
            'token' => $token, 'email' => 'alice@alpha.test',
            'password' => 'brand-new-password-4', 'password_confirmation' => 'brand-new-password-4',
        ]);

        $this->assertFalse($this->employeeWithEmail->fresh()->must_change_password);

        // And they land on their dashboard rather than the change-password gate.
        $this->post('/login', ['identifier' => 'alice@alpha.test', 'password' => 'brand-new-password-4'])
            ->assertRedirect('/employee/dashboard');
    }

    public function test_the_new_password_must_meet_the_policy(): void
    {
        $token = Password::broker()->createToken($this->employeeWithEmail);

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token, 'email' => 'alice@alpha.test',
            'password' => 'allletters', 'password_confirmation' => 'allletters',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('old-password-1', $this->employeeWithEmail->fresh()->password));
    }

    // ------------------------------------------------- the placeholder address

    /**
     * The stand-in address is never written to, so a link sent there would
     * leave the employee waiting for a mail that cannot arrive. Their HR team
     * resets them, which is the path that already exists.
     */
    public function test_a_placeholder_address_is_never_mailed(): void
    {
        Notification::fake();

        $this->request('emp102@alpha1.local')->assertSessionHas('message', self::GENERIC);

        Notification::assertNothingSent();
    }

    public function test_no_token_is_created_for_a_placeholder_address(): void
    {
        $this->request('emp102@alpha1.local');

        $this->assertEquals(0, DB::table('password_reset_tokens')->count());
    }

    public function test_the_form_explains_the_alternative(): void
    {
        $page = file_get_contents(resource_path('js/Pages/Auth/ForgotPassword.vue'));

        $this->assertStringContainsString('HR team', $page);
    }

    /**
     * The suffix is config, shared with the code that mints those addresses, so
     * the two cannot disagree about what counts as unreachable.
     */
    public function test_the_placeholder_suffix_comes_from_config(): void
    {
        $this->assertEquals('.local', config('mealbells.placeholder_email_suffix'));

        $this->assertTrue($this->admin->canReceiveMail());
        $this->assertFalse($this->employeeWithPlaceholder->canReceiveMail());
    }

    // --------------------------------------------------------- enumeration

    /**
     * Saying "no such account" would turn this form into a way to find out
     * which addresses are registered - in a company's meal portal, a list of
     * who works there.
     */
    public function test_an_unknown_address_gets_the_same_answer(): void
    {
        Notification::fake();

        $this->request('nobody@alpha.test')
            ->assertSessionHas('message', self::GENERIC)
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_an_inactive_employee_gets_the_same_answer_and_no_link(): void
    {
        Notification::fake();

        Employee::where('user_id', $this->employeeWithEmail->id)->update(['status' => 'inactive']);

        $this->request('alice@alpha.test')->assertSessionHas('message', self::GENERIC);

        Notification::assertNothingSent();
    }

    // -------------------------------------------------------------- the token

    public function test_an_expired_token_is_refused(): void
    {
        $token = Password::broker()->createToken($this->employeeWithEmail);

        // The broker's window is config('auth.passwords.users.expire') minutes.
        $minutes = (int) config('auth.passwords.users.expire', 60);
        DB::table('password_reset_tokens')
            ->where('email', 'alice@alpha.test')
            ->update(['created_at' => now()->subMinutes($minutes + 5)]);

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token, 'email' => 'alice@alpha.test',
            'password' => 'brand-new-password-4', 'password_confirmation' => 'brand-new-password-4',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('old-password-1', $this->employeeWithEmail->fresh()->password));
    }

    public function test_a_token_works_only_once(): void
    {
        $token = Password::broker()->createToken($this->employeeWithEmail);

        $payload = [
            'token' => $token, 'email' => 'alice@alpha.test',
            'password' => 'brand-new-password-4', 'password_confirmation' => 'brand-new-password-4',
        ];

        $this->post('/reset-password', $payload)->assertRedirect(route('login'));

        $second = ['...' => null] + $payload;
        $second['password'] = 'another-password-5';
        $second['password_confirmation'] = 'another-password-5';

        $this->from('/reset-password/'.$token)->post('/reset-password', $second)
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('brand-new-password-4', $this->employeeWithEmail->fresh()->password));
    }

    /**
     * A token belongs to the address it was issued for, so it cannot be used to
     * take over a different account.
     */
    public function test_a_token_cannot_be_used_for_another_account(): void
    {
        $token = Password::broker()->createToken($this->employeeWithEmail);

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token, 'email' => 'hr@alpha.test',
            'password' => 'brand-new-password-4', 'password_confirmation' => 'brand-new-password-4',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('old-password-1', $this->admin->fresh()->password));
    }

    public function test_a_made_up_token_is_refused(): void
    {
        $this->from('/reset-password/whatever')->post('/reset-password', [
            'token' => str_repeat('a', 64), 'email' => 'alice@alpha.test',
            'password' => 'brand-new-password-4', 'password_confirmation' => 'brand-new-password-4',
        ])->assertSessionHasErrors('email');
    }

    // ------------------------------------------------------------- throttling

    /**
     * The answer is identical either way, so without a limit this form is a way
     * to mail-bomb one address, or to walk a list of them.
     */
    public function test_requests_for_one_address_are_throttled(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->request('hr@alpha.test')->assertStatus(302);
        }

        $this->request('hr@alpha.test')->assertStatus(429);
    }

    public function test_the_reset_form_is_throttled_too(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/reset-password', [
                'token' => str_repeat('a', 64), 'email' => 'guesser@alpha.test',
                'password' => 'brand-new-password-4', 'password_confirmation' => 'brand-new-password-4',
            ]);
        }

        $this->post('/reset-password', [
            'token' => str_repeat('a', 64), 'email' => 'guesser@alpha.test',
            'password' => 'brand-new-password-4', 'password_confirmation' => 'brand-new-password-4',
        ])->assertStatus(429);
    }
}
