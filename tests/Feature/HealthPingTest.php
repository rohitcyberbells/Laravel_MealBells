<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The endpoint a monitor can actually reach.
 *
 * `/up` answers 200 with the database unreachable, the worker stopped and the
 * scheduler dead, so it stays green through every failure this application
 * has. These assert that /health/ping does not.
 */
class HealthPingTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'test-health-token-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('health.ping_token', self::TOKEN);

        // A live scheduler by default, so each test fails only the thing it is
        // about.
        Cache::put('scheduler_last_run', now()->timestamp);
    }

    public function test_it_answers_ok_when_everything_is_well(): void
    {
        $this->getJson('/health/ping?token='.self::TOKEN)
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('failing', []);
    }

    public function test_the_token_may_be_sent_as_a_header(): void
    {
        $this->getJson('/health/ping', ['X-Health-Token' => self::TOKEN])
            ->assertStatus(200);
    }

    /**
     * 404 rather than 401, both for a wrong token and for none: a monitoring
     * endpoint that confirms its own existence to an unauthenticated caller,
     * and names the component that is down, is reconnaissance.
     */
    public function test_a_wrong_or_missing_token_is_indistinguishable_from_no_such_path(): void
    {
        $this->getJson('/health/ping')->assertStatus(404);
        $this->getJson('/health/ping?token=wrong')->assertStatus(404);
    }

    public function test_with_no_token_configured_the_endpoint_does_not_exist(): void
    {
        config()->set('health.ping_token', null);

        $this->getJson('/health/ping?token='.self::TOKEN)->assertStatus(404);
        $this->getJson('/health/ping')->assertStatus(404);
    }

    public function test_a_failing_check_answers_503_and_names_itself(): void
    {
        Cache::forget('scheduler_last_run');

        $this->getJson('/health/ping?token='.self::TOKEN)
            ->assertStatus(503)
            ->assertJsonPath('status', 'failing')
            ->assertJsonPath('checks.scheduler.ok', false)
            ->assertJsonFragment(['failing' => ['scheduler']]);
    }

    public function test_a_stale_scheduler_heartbeat_fails(): void
    {
        Cache::put('scheduler_last_run', now()->subMinutes(30)->timestamp);

        $this->getJson('/health/ping?token='.self::TOKEN)
            ->assertStatus(503)
            ->assertJsonPath('checks.scheduler.ok', false)
            ->assertJsonPath('checks.scheduler.minutes_ago', 30);
    }

    public function test_piled_up_failed_jobs_fail(): void
    {
        config()->set('health.queue.failed_jobs_threshold', 2);

        for ($i = 0; $i < 3; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'database', 'queue' => 'default',
                'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
            ]);
        }

        $this->getJson('/health/ping?token='.self::TOKEN)
            ->assertStatus(503)
            ->assertJsonPath('checks.queue_failures.ok', false)
            ->assertJsonPath('checks.queue_failures.failed_jobs', 3);
    }

    /**
     * The failure that costs most. Every notification in MealBells is queued,
     * so a stopped worker loses leave silently - and there is no worker
     * heartbeat to read, only the work left sitting.
     */
    public function test_a_job_left_waiting_is_reported_as_no_worker(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(45)->timestamp,
            'created_at' => now()->subMinutes(45)->timestamp,
        ]);

        $this->getJson('/health/ping?token='.self::TOKEN)
            ->assertStatus(503)
            ->assertJsonPath('checks.queue_worker.ok', false)
            ->assertJsonPath('checks.queue_worker.oldest_pending_job_minutes', 45);
    }

    /**
     * An empty queue is no evidence either way. A monitor must not page someone
     * because nothing happened to be queued at 3am.
     */
    public function test_an_empty_queue_is_not_treated_as_a_dead_worker(): void
    {
        $this->getJson('/health/ping?token='.self::TOKEN)
            ->assertStatus(200)
            ->assertJsonPath('checks.queue_worker.ok', true);
    }

    public function test_a_job_queued_moments_ago_is_not_a_dead_worker(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->getJson('/health/ping?token='.self::TOKEN)
            ->assertStatus(200)
            ->assertJsonPath('checks.queue_worker.ok', true);
    }

    public function test_a_reserved_job_is_not_counted_as_waiting(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 1,
            'reserved_at' => now()->subMinutes(2)->timestamp,
            'available_at' => now()->subMinutes(45)->timestamp,
            'created_at' => now()->subMinutes(45)->timestamp,
        ]);

        $this->getJson('/health/ping?token='.self::TOKEN)
            ->assertStatus(200)
            ->assertJsonPath('checks.queue_worker.ok', true);
    }

    public function test_a_stale_hrms_pull_fails(): void
    {
        $company = Company::create(['name' => 'Acme', 'code' => 'ACME01']);

        CompanyHrmsConnection::create([
            'company_id' => $company->id,
            'pull_adapter' => 'cyberpulse',
            'pull_base_url' => 'https://hrms.example.test',
            'last_pull_at' => now()->subHours(6),
            'last_pull_status' => 'success',
        ]);

        $this->getJson('/health/ping?token='.self::TOKEN)
            ->assertStatus(503)
            ->assertJsonPath('checks.hrms_pull.ok', false)
            ->assertJsonPath('checks.hrms_pull.stale', 1);
    }

    public function test_a_site_that_polls_no_hrms_is_unaffected(): void
    {
        $this->getJson('/health/ping?token='.self::TOKEN)
            ->assertStatus(200)
            ->assertJsonPath('checks.hrms_pull.ok', true);
    }

    public function test_pull_staleness_can_be_reported_without_failing_the_endpoint(): void
    {
        config()->set('health.pull_staleness_fails', false);

        $company = Company::create(['name' => 'Acme', 'code' => 'ACME01']);
        CompanyHrmsConnection::create([
            'company_id' => $company->id, 'pull_adapter' => 'cyberpulse',
            'pull_base_url' => 'https://hrms.example.test',
            'last_pull_at' => now()->subHours(6), 'last_pull_status' => 'success',
        ]);

        $this->getJson('/health/ping?token='.self::TOKEN)
            ->assertStatus(200)
            ->assertJsonPath('checks.hrms_pull.ok', true)
            ->assertJsonPath('checks.hrms_pull.stale', 1);
    }

    /**
     * The response is readable by anyone holding the monitor's token, which is
     * a value that ends up in a monitoring vendor's configuration.
     */
    public function test_the_response_leaks_no_company_or_connection_detail(): void
    {
        $company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);
        CompanyHrmsConnection::create([
            'company_id' => $company->id, 'pull_adapter' => 'cyberpulse',
            'pull_base_url' => 'https://hrms.internal.acme.test',
            'pull_username' => 'svc_mealbells',
            'last_pull_at' => now(), 'last_pull_status' => 'success',
        ]);

        $body = $this->getJson('/health/ping?token='.self::TOKEN)->getContent();

        $this->assertStringNotContainsString('Acme Industries', $body);
        $this->assertStringNotContainsString('hrms.internal.acme.test', $body);
        $this->assertStringNotContainsString('svc_mealbells', $body);
        $this->assertStringNotContainsString(self::TOKEN, $body);
    }

    /**
     * Anything already pointed at /up keeps working, and it stays the dumb
     * check it was - this endpoint is an addition, not a replacement.
     */
    public function test_up_is_unchanged(): void
    {
        $this->get('/up')->assertStatus(200);
    }

    /**
     * No session is started, so a monitor polling every minute cannot fill the
     * sessions table.
     */
    public function test_polling_does_not_start_a_session(): void
    {
        $response = $this->getJson('/health/ping?token='.self::TOKEN);

        $this->assertNull(
            collect($response->headers->getCookies())
                ->first(fn ($cookie) => $cookie->getName() === config('session.cookie')),
        );
    }

    public function test_it_reports_when_it_checked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'Asia/Kolkata'));

        $this->getJson('/health/ping?token='.self::TOKEN)
            ->assertJsonPath('checked_at', now()->toIso8601String());
    }
}
