<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\HrmsWebhookEvent;
use App\Models\Skip;
use App\Models\User;
use App\Services\Hrms\HrmsConnectionResolver;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Three things that are useful while onboarding a vendor and unacceptable once
 * real people's meals are in the database.
 *
 * The test event and hrms:simulate both post a correctly signed event through
 * the real pipeline, so they cancel real meals - and afterwards nothing
 * distinguishes what they produced from a genuine event.
 */
class HrmsProductionGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('inertia.ssr.enabled', false);

        $this->company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->admin = User::create([
            'name' => 'Alpha HR', 'email' => 'hr@alpha.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'EMP101', 'external_id' => 'HR-1',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        CompanyHrmsConnection::create([
            'company_id' => $this->company->id,
            'webhook_secret' => 'whsec_test_secret_value',
        ]);
    }

    protected function pressTestEvent()
    {
        return $this->actingAs($this->admin)->from('/company-admin/hrms')
            // CSRF is normally skipped in tests because the framework checks
            // environment('testing') - and these tests flip the environment to
            // 'production', which turns it back on and makes every POST a 419.
            // The subject here is the production guard, not CSRF, which has its
            // own coverage. The class is PreventRequestForgery in Laravel 13.
            ->withoutMiddleware(PreventRequestForgery::class)
            ->post('/company-admin/hrms/test-event', ['event' => 'leave_approved', 'employee' => 'HR-1']);
    }

    /** @return array<string, mixed> */
    protected function hrmsProps(): array
    {
        return $this->actingAs($this->admin)->get('/company-admin/hrms')
            ->getOriginalContent()->getData()['page']['props'];
    }

    // ------------------------------------------------------- the test button

    public function test_outside_production_the_test_event_is_really_delivered(): void
    {
        $this->pressTestEvent()->assertRedirect('/company-admin/hrms');

        $result = $this->hrmsProps()['test_result'];

        $this->assertFalse($result['dry_run']);
        $this->assertEquals(202, $result['status']);
        // It went through the pipeline, which is the point of it here.
        $this->assertNotNull($result['event_status']);
        $this->assertEquals(1, HrmsWebhookEvent::count());
    }

    public function test_in_production_the_test_event_writes_nothing(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->pressTestEvent()->assertRedirect('/company-admin/hrms');

        $result = $this->hrmsProps()['test_result'];

        $this->assertTrue($result['dry_run']);
        $this->assertNull($result['status'], 'nothing was delivered, so there is no HTTP status');
        $this->assertNull($result['event_status']);

        // The important part: no event, and above all no skip.
        $this->assertEquals(0, HrmsWebhookEvent::count());
        $this->assertEquals(0, Skip::count());
    }

    /**
     * It still has to be useful: the signature and payload are what a vendor
     * needs in order to send the same shape themselves.
     */
    public function test_the_production_dry_run_still_reports_the_event_it_built(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->pressTestEvent();
        $result = $this->hrmsProps()['test_result'];

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['event_id']);
        $this->assertTrue($result['employee_matched']);
    }

    public function test_the_page_says_when_nothing_was_delivered(): void
    {
        $page = file_get_contents(resource_path('js/Pages/CompanyAdmin/Hrms/Index.vue'));

        $this->assertStringContainsString('test_result.dry_run', $page);
        $this->assertStringContainsString('not delivered', $page);
    }

    // ---------------------------------------------------------- the command

    public function test_the_simulate_command_runs_outside_production(): void
    {
        $this->artisan('hrms:simulate', [
            '--company' => 'ALPHA1', '--employee' => 'HR-1', '--print' => true,
        ])->assertSuccessful();
    }

    public function test_the_simulate_command_refuses_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('hrms:simulate', ['--company' => 'ALPHA1', '--employee' => 'HR-1'])
            ->expectsOutputToContain('refused in production')
            ->assertFailed();

        $this->assertEquals(0, HrmsWebhookEvent::count());
        $this->assertEquals(0, Skip::count());
    }

    /**
     * Refused before anything is built, so it cannot even reach the network.
     */
    public function test_the_refusal_happens_before_any_work(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('hrms:simulate', ['--company' => 'ALPHA1', '--employee' => 'HR-1'])->assertFailed();

        Http::assertNothingSent();
    }

    // ------------------------------------------------- the config fallback

    public function test_a_config_secret_is_honoured_outside_production(): void
    {
        CompanyHrmsConnection::where('company_id', $this->company->id)->delete();

        config()->set("hrms.companies.{$this->company->id}.webhook", [
            'secret' => 'whsec_from_config',
            'auth' => 'signature',
        ]);

        $webhook = app(HrmsConnectionResolver::class)->webhookFor($this->company->fresh());

        $this->assertEquals('whsec_from_config', $webhook['secret']);
    }

    /**
     * A tenant's credential belongs in its encrypted column, not in a deployed
     * config file. Falling back in production would hide a missing row instead
     * of surfacing it.
     */
    public function test_a_config_secret_is_ignored_in_production(): void
    {
        CompanyHrmsConnection::where('company_id', $this->company->id)->delete();

        config()->set("hrms.companies.{$this->company->id}.webhook", [
            'secret' => 'whsec_from_config',
            'auth' => 'signature',
        ]);

        app()->detectEnvironment(fn () => 'production');

        $this->assertNull(app(HrmsConnectionResolver::class)->webhookFor($this->company->fresh()));
    }

    /**
     * The database row is what production uses, and it still works there.
     */
    public function test_the_database_secret_still_works_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $webhook = app(HrmsConnectionResolver::class)->webhookFor($this->company->fresh());

        $this->assertEquals('whsec_test_secret_value', $webhook['secret']);
    }

    /**
     * And with no row at all, production refuses the webhook rather than
     * accepting it on a default - the endpoint fails closed.
     */
    public function test_with_no_secret_anywhere_production_has_none(): void
    {
        CompanyHrmsConnection::where('company_id', $this->company->id)->delete();

        app()->detectEnvironment(fn () => 'production');

        $this->assertNull(app(HrmsConnectionResolver::class)->webhookFor($this->company->fresh()));
        $this->assertFalse(app(HrmsConnectionResolver::class)->hasSecret($this->company->fresh()));
    }
}
