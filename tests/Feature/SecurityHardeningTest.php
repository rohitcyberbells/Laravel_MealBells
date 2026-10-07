<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Response headers, one password rule, a bounded import and a sign-in limit
 * that is not only per IP.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);
        RateLimiter::clear('login');

        $this->company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->admin = User::create([
            'name' => 'Alpha HR', 'email' => 'hr@alpha.test', 'password' => bcrypt('password-1'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);
    }

    // ----------------------------------------------------------------- headers

    /** @return array<int, array<int, string>> */
    public static function expectedHeaders(): array
    {
        return [
            ['X-Frame-Options', 'DENY'],
            ['X-Content-Type-Options', 'nosniff'],
            ['Referrer-Policy', 'strict-origin-when-cross-origin'],
        ];
    }

    #[DataProvider('expectedHeaders')]
    public function test_every_response_carries_the_header(string $header, string $value): void
    {
        $this->get('/login')->assertHeader($header, $value);
    }

    public function test_unused_browser_features_are_refused(): void
    {
        $policy = $this->get('/login')->headers->get('Permissions-Policy');

        foreach (['camera', 'microphone', 'geolocation'] as $feature) {
            $this->assertStringContainsString("{$feature}=()", $policy);
        }
    }

    /**
     * A CSP written blind would break Vite and Inertia rather than protect
     * anything, so it starts as a report: the browser says what it would have
     * blocked while everything keeps working.
     */
    public function test_the_content_security_policy_starts_in_report_only(): void
    {
        $response = $this->get('/login');

        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_the_policy_can_be_enforced_by_configuration(): void
    {
        config()->set('security.csp.enforce', true);

        $response = $this->get('/login');

        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_the_policy_refuses_framing_and_plugins(): void
    {
        $policy = $this->get('/login')->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
    }

    /**
     * HSTS over plain http is meaningless, and sending it from a dev server
     * would pin the browser to https for localhost across every project on the
     * machine.
     */
    public function test_hsts_is_absent_over_plain_http(): void
    {
        $this->assertNull($this->get('/login')->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_is_sent_over_https(): void
    {
        $header = $this->get('https://localhost/login')->headers->get('Strict-Transport-Security');

        $this->assertNotNull($header);
        $this->assertStringContainsString('max-age=', $header);
        $this->assertStringContainsString('includeSubDomains', $header);
    }

    // ---------------------------------------------------------------- password

    /**
     * It was min:8 for a forced change and min:6 for creating an admin and for
     * the HRMS credential, so the weakest rule governed the most privileged
     * account. One definition now.
     */
    public function test_one_password_rule_is_defined_for_the_whole_app(): void
    {
        $rule = Password::defaults();

        $this->assertFalse($this->passes($rule, 'short1'), 'eight characters is the floor');
        $this->assertFalse($this->passes($rule, 'alllettersonly'), 'a number is required');
        $this->assertFalse($this->passes($rule, '1234567890'), 'a letter is required');
        $this->assertTrue($this->passes($rule, 'good-password-1'));
    }

    /** @return array<int, array<int, string>> */
    public static function placesAPasswordIsSet(): array
    {
        return [
            ['app/Http/Controllers/Auth/LoginController.php'],
            ['app/Http/Controllers/SuperAdmin/SuperAdminController.php'],
            ['app/Http/Controllers/CompanyAdmin/CompanyHrmsController.php'],
        ];
    }

    #[DataProvider('placesAPasswordIsSet')]
    public function test_no_controller_spells_its_own_password_rule(string $file): void
    {
        $source = file_get_contents(base_path($file));

        $this->assertStringContainsString('Password::defaults()', $source);
        $this->assertStringNotContainsString('min:6', $source);
        $this->assertStringNotContainsString('|min:8', $source);
    }

    public function test_a_weak_admin_password_is_refused_at_the_route(): void
    {
        $root = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test',
            'password' => bcrypt('password-1'), 'role' => 'super_admin',
        ]);

        $this->actingAs($root)->from('/super-admin/dashboard')
            ->post('/super-admin/companies', [
                'name' => 'Beta', 'address' => 'Addr', 'contact_phone' => '9876543210',
                'admin_name' => 'Beta HR', 'admin_email' => 'hr@beta.test',
                'admin_password' => 'abc123',
            ])
            ->assertSessionHasErrors('admin_password');
    }

    // ------------------------------------------------------------ import cap

    /**
     * The upload is capped by file size, but the confirm step takes JSON rows -
     * so without a cap a crafted request hands the importer an unbounded array.
     */
    public function test_an_oversized_import_is_refused(): void
    {
        $cap = (int) config('mealbells.max_import_rows');
        $rows = [];

        for ($i = 0; $i <= $cap; $i++) {
            $rows[] = ['employee_code' => 'E'.$i, 'name' => 'Person '.$i];
        }

        $this->actingAs($this->admin)->from('/company-admin/employees')
            ->post('/company-admin/employees/csv-import', ['rows' => $rows])
            ->assertSessionHasErrors('rows');

        $this->assertEquals(0, Employee::count());
    }

    public function test_an_import_at_the_cap_is_accepted(): void
    {
        config()->set('mealbells.max_import_rows', 3);

        $this->actingAs($this->admin)->from('/company-admin/employees')
            ->post('/company-admin/employees/csv-import', ['rows' => [
                ['employee_code' => 'E1', 'name' => 'One'],
                ['employee_code' => 'E2', 'name' => 'Two'],
                ['employee_code' => 'E3', 'name' => 'Three'],
            ]])
            ->assertSessionHasNoErrors();

        $this->assertEquals(3, Employee::count());
    }

    // ------------------------------------------------------------- login limit

    /**
     * Per IP alone is the wrong shape on its own: an office behind one address
     * shares the budget, while an attacker spraying one password across many
     * accounts from many addresses never trips it.
     */
    public function test_one_account_is_limited_sooner_than_the_address(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['identifier' => 'hr@alpha.test', 'password' => 'wrong'])
                ->assertStatus(302);
        }

        // Sixth attempt on the same account.
        $this->post('/login', ['identifier' => 'hr@alpha.test', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    /**
     * And a colleague on the same address is not locked out by it, which is
     * exactly what a single per-IP limit did.
     */
    public function test_another_account_from_the_same_address_still_gets_through(): void
    {
        $other = User::create([
            'name' => 'Other HR', 'email' => 'hr2@alpha.test', 'password' => bcrypt('password-1'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['identifier' => 'hr@alpha.test', 'password' => 'wrong']);
        }

        // Same IP, different account, correct password.
        $this->post('/login', ['identifier' => 'hr2@alpha.test', 'password' => 'password-1'])
            ->assertRedirect('/company-admin/dashboard');

        $this->assertAuthenticatedAs($other);
    }

    /**
     * The address limit is still there, so spraying many accounts from one
     * address is bounded too.
     */
    public function test_the_address_is_still_limited_across_accounts(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $response = $this->post('/login', [
                'identifier' => "nobody{$i}@alpha.test", 'password' => 'wrong',
            ]);

            if ($response->status() === 429) {
                $this->assertGreaterThan(5, $i, 'the per-address budget is wider than one account');

                return;
            }
        }

        $this->fail('the per-address limit never applied');
    }

    protected function passes(Password $rule, string $value): bool
    {
        return validator(['p' => $value], ['p' => $rule])->passes();
    }
}
