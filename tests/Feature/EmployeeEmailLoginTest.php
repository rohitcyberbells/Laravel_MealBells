<?php

namespace Tests\Feature;

use App\Actions\Employee\CreateEmployeeLogins;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\EmployeeLoginCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class EmployeeEmailLoginTest extends TestCase
{
    use RefreshDatabase;

    protected Company $acme;

    protected Company $other;

    protected User $acmeAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('');

        $this->acme = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);
        $this->other = Company::create(['name' => 'Other Corp', 'code' => 'OTHR01']);

        foreach ([$this->acme, $this->other] as $company) {
            CompanySetting::create([
                'company_id' => $company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
                'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            ]);
        }

        $this->acmeAdmin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->acme->id,
        ]);
    }

    protected function employee(Company $company, string $code, ?string $email, string $status = 'active'): Employee
    {
        return Employee::create([
            'company_id' => $company->id, 'employee_code' => $code, 'email' => $email,
            'name' => "Employee {$code}", 'status' => $status, 'is_meal_eligible' => true,
        ]);
    }

    /** Provision a login and return the generated credential row. */
    protected function provision(Company $company, Employee $employee, ?User $admin = null): array
    {
        return (new CreateEmployeeLogins)->execute(
            $company, [$employee->id], $admin ?? $this->acmeAdmin
        )[0];
    }

    public function test_an_employee_signs_in_with_their_email(): void
    {
        $employee = $this->employee($this->acme, 'ACME001', 'acme001@demo.test');
        $credential = $this->provision($this->acme, $employee);

        $this->post('/login', [
            'identifier' => 'acme001@demo.test',
            'password' => $credential['temporary_password'],
        ])->assertRedirect('/employee/dashboard');

        $this->assertAuthenticatedAs($employee->fresh()->user);
    }

    public function test_a_wrong_password_and_an_unknown_email_give_the_same_message(): void
    {
        $employee = $this->employee($this->acme, 'ACME001', 'acme001@demo.test');
        $this->provision($this->acme, $employee);

        $this->post('/login', [
            'identifier' => 'acme001@demo.test', 'password' => 'not-the-password',
        ])->assertSessionHasErrors('identifier');

        $wrongPasswordMessage = session('errors')->first('identifier');

        $this->flushSession();

        $this->post('/login', [
            'identifier' => 'nobody@demo.test', 'password' => 'anything',
        ])->assertSessionHasErrors('identifier');

        $unknownEmailMessage = session('errors')->first('identifier');

        // Identical wording, so the form cannot be used to discover which
        // addresses are registered.
        $this->assertEquals($wrongPasswordMessage, $unknownEmailMessage);
        $this->assertStringContainsString('do not match our records', $unknownEmailMessage);
        $this->assertGuest();
    }

    public function test_an_inactive_employee_is_refused_by_email_with_the_same_generic_message(): void
    {
        $employee = $this->employee($this->acme, 'ACME001', 'acme001@demo.test');
        $credential = $this->provision($this->acme, $employee);

        $employee->update(['status' => 'inactive']);

        $response = $this->post('/login', [
            'identifier' => 'acme001@demo.test', 'password' => $credential['temporary_password'],
        ]);

        $response->assertSessionHasErrors('identifier');
        $this->assertStringContainsString('do not match our records', session('errors')->first('identifier'));
        $this->assertGuest();
    }

    public function test_an_employee_without_an_email_still_gets_the_company_code_route(): void
    {
        $employee = $this->employee($this->acme, 'ACME002', null);
        $credential = $this->provision($this->acme, $employee);

        // The admin is told why no mail went out.
        $this->assertNull($credential['email']);
        $this->assertFalse($credential['can_login_with_email']);
        $this->assertFalse($credential['mail_sent']);
        $this->assertEquals('ACME01', $credential['company_code']);

        $this->post('/login', [
            'identifier' => 'ACME002',
            'company_code' => 'ACME01',
            'password' => $credential['temporary_password'],
        ])->assertRedirect('/employee/dashboard');

        $this->assertAuthenticatedAs($employee->fresh()->user);
    }

    public function test_an_employee_code_without_a_company_code_is_told_what_is_missing(): void
    {
        $employee = $this->employee($this->acme, 'ACME002', null);
        $credential = $this->provision($this->acme, $employee);

        $this->post('/login', [
            'identifier' => 'ACME002', 'password' => $credential['temporary_password'],
        ])->assertSessionHasErrors('company_code');

        $this->assertGuest();
    }

    public function test_an_email_from_one_company_cannot_be_used_against_another(): void
    {
        $acmeEmployee = $this->employee($this->acme, 'ACME001', 'shared@demo.test');
        $credential = $this->provision($this->acme, $acmeEmployee);

        $otherAdmin = User::create([
            'name' => 'Other HR', 'email' => 'hr@other.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->other->id,
        ]);

        // The same address on another company's roster cannot claim the login:
        // users.email is unique, so the second provisioning falls back to the
        // code route rather than hijacking the first account.
        $otherEmployee = $this->employee($this->other, 'OTHR001', 'shared@demo.test');
        $otherCredential = $this->provision($this->other, $otherEmployee, $otherAdmin);

        $this->assertNull($otherCredential['email']);
        $this->assertFalse($otherCredential['can_login_with_email']);

        $this->post('/login', [
            'identifier' => 'shared@demo.test', 'password' => $credential['temporary_password'],
        ])->assertRedirect('/employee/dashboard');

        // Signed in as Acme's employee, never the other company's.
        $this->assertAuthenticatedAs($acmeEmployee->fresh()->user);
        $this->assertEquals($this->acme->id, auth()->user()->company_id);
    }

    public function test_provisioning_mails_the_temporary_password_and_still_shows_it_once(): void
    {
        Notification::fake();

        $employee = $this->employee($this->acme, 'ACME001', 'acme001@demo.test');

        $response = $this->actingAs($this->acmeAdmin)->post('/company-admin/employees/logins', [
            'employee_ids' => [$employee->id],
        ]);

        $response->assertSessionHas('credentials');

        $credential = session('credentials')[0];
        $this->assertNotEmpty($credential['temporary_password']);
        $this->assertTrue($credential['mail_sent']);

        Notification::assertSentTo(
            $employee->fresh()->user,
            EmployeeLoginCreatedNotification::class,
            fn ($notification) => $notification->temporaryPassword === $credential['temporary_password']
                && $notification->employeeCode === 'ACME001'
        );
    }

    public function test_no_mail_is_sent_to_an_employee_without_an_email(): void
    {
        Notification::fake();

        $employee = $this->employee($this->acme, 'ACME002', null);
        $this->provision($this->acme, $employee);

        Notification::assertNothingSent();
    }

    public function test_the_mail_carries_the_sign_in_link_and_both_routes(): void
    {
        $employee = $this->employee($this->acme, 'ACME001', 'acme001@demo.test');
        $credential = $this->provision($this->acme, $employee);

        $mail = (new EmployeeLoginCreatedNotification($this->acme, 'ACME001', $credential['temporary_password']))
            ->toMail($employee->fresh()->user);

        $payload = $mail->toArray();
        $lines = implode(' ', [...$payload['introLines'], ...$payload['outroLines']]);

        $this->assertEquals(url('/login'), $payload['actionUrl']);
        $this->assertStringContainsString($credential['temporary_password'], $lines);

        // Both routes are spelled out, since an employee may not know either.
        $this->assertStringContainsString('ACME01', $lines);
        $this->assertStringContainsString('ACME001', $lines);
    }

    public function test_a_freshly_provisioned_employee_must_change_their_password(): void
    {
        $employee = $this->employee($this->acme, 'ACME001', 'acme001@demo.test');
        $credential = $this->provision($this->acme, $employee);

        $this->post('/login', [
            'identifier' => 'acme001@demo.test', 'password' => $credential['temporary_password'],
        ])->assertRedirect('/employee/dashboard');

        // The middleware still diverts every other page until it is changed.
        $this->get('/employee/dashboard')->assertRedirect('/change-password');

        $this->post('/change-password', [
            'current_password' => $credential['temporary_password'],
            'password' => 'a-better-password-7',
            'password_confirmation' => 'a-better-password-7',
        ])->assertRedirect('/');

        $this->assertFalse($employee->fresh()->user->must_change_password);
        $this->get('/employee/dashboard')->assertStatus(200);
    }

    public function test_login_is_rate_limited(): void
    {
        $this->employee($this->acme, 'ACME001', 'acme001@demo.test');

        // The route allows five attempts a minute.
        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['identifier' => 'acme001@demo.test', 'password' => 'wrong'])
                ->assertStatus(302);
        }

        $this->post('/login', ['identifier' => 'acme001@demo.test', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_the_other_roles_still_sign_in_with_the_unified_field(): void
    {
        $this->post('/login', ['identifier' => 'hr@acme.test', 'password' => 'password'])
            ->assertRedirect('/company-admin/dashboard');

        $this->assertAuthenticatedAs($this->acmeAdmin);
    }

    public function test_the_legacy_email_field_still_works(): void
    {
        $this->post('/login', ['email' => 'hr@acme.test', 'password' => 'password'])
            ->assertRedirect('/company-admin/dashboard');
    }
}
