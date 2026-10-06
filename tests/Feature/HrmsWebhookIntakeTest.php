<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HrmsWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 1 covers intake only: authentication, replay protection and dedupe.
 * Nothing is applied to skips yet, so every accepted event stays 'received'.
 */
class HrmsWebhookIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected string $secretA = 'whsec_company_a';

    protected string $secretB = 'whsec_company_b';

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);
        $this->companyB = Company::create(['name' => 'Beta Corp', 'code' => 'BETA1']);

        $this->configureWebhook($this->companyA->id, $this->secretA);
        $this->configureWebhook($this->companyB->id, $this->secretB);
    }

    protected function configureWebhook(int $companyId, string $secret, string $auth = 'signature'): void
    {
        config()->set("hrms.companies.{$companyId}", [
            'webhook' => [
                'auth' => $auth,
                'secret' => $secret,
                'signature_header' => 'X-Hrms-Signature',
                'timestamp_header' => 'X-Hrms-Timestamp',
                'tolerance_seconds' => 300,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    protected function payload(string $eventId = 'evt-1', string $leaveId = 'L-1'): array
    {
        return [
            'event_id' => $eventId,
            'event_type' => 'leave_approved',
            'occurred_at' => '2026-10-05T02:30:00Z',
            'leave' => [
                'id' => $leaveId,
                'employee_id' => 'HR-1',
                'from_date' => '2026-10-06',
                'to_date' => '2026-10-06',
                'type' => 'Casual Leave',
            ],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    protected function send(array $payload, ?Company $company = null, ?string $secret = null, ?string $timestamp = null)
    {
        $company ??= $this->companyA;
        $secret ??= $this->secretA;
        $timestamp ??= (string) now()->timestamp;

        $body = json_encode($payload);

        return $this->withHeaders([
            'X-Hrms-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
            'X-Hrms-Timestamp' => $timestamp,
        ])->postJson("/api/hrms/{$company->code}/events", $payload);
    }

    public function test_correctly_signed_event_is_accepted_and_recorded(): void
    {
        $this->send($this->payload())
            ->assertStatus(202)
            ->assertJson(['event_id' => 'evt-1', 'duplicate' => false]);

        $event = HrmsWebhookEvent::sole();
        $this->assertEquals($this->companyA->id, $event->company_id);
        $this->assertEquals('evt-1', $event->external_event_id);
        $this->assertEquals('leave_approved', $event->event_type);
        $this->assertEquals('L-1', $event->leave_external_id);
        $this->assertEquals('received', $event->status);
        $this->assertNotNull($event->occurred_at);
        $this->assertEquals('HR-1', $event->payload['leave']['employee_id']);
    }

    public function test_wrong_signature_is_rejected_and_nothing_is_recorded(): void
    {
        $this->send($this->payload(), secret: 'whsec_wrong')->assertStatus(401);

        $this->assertEquals(0, HrmsWebhookEvent::count());
    }

    public function test_missing_signature_headers_are_rejected(): void
    {
        $this->postJson("/api/hrms/{$this->companyA->code}/events", $this->payload())
            ->assertStatus(401);

        $this->assertEquals(0, HrmsWebhookEvent::count());
    }

    public function test_timestamp_outside_the_replay_window_is_rejected(): void
    {
        $this->send($this->payload(), timestamp: (string) now()->subHour()->timestamp)
            ->assertStatus(401);

        $this->assertEquals(0, HrmsWebhookEvent::count());
    }

    public function test_timestamp_just_inside_the_replay_window_is_accepted(): void
    {
        $this->send($this->payload(), timestamp: (string) now()->subSeconds(290)->timestamp)
            ->assertStatus(202);
    }

    public function test_tampered_body_fails_the_signature(): void
    {
        $signedBody = json_encode($this->payload());
        $timestamp = (string) now()->timestamp;

        $tampered = $this->payload();
        $tampered['leave']['employee_id'] = 'HR-999';

        $this->withHeaders([
            'X-Hrms-Signature' => hash_hmac('sha256', $timestamp.'.'.$signedBody, $this->secretA),
            'X-Hrms-Timestamp' => $timestamp,
        ])->postJson("/api/hrms/{$this->companyA->code}/events", $tampered)
            ->assertStatus(401);
    }

    public function test_repeat_delivery_is_acknowledged_as_duplicate_and_stored_once(): void
    {
        $payload = $this->payload();

        $this->send($payload)->assertStatus(202)->assertJson(['duplicate' => false]);
        $this->send($payload)->assertStatus(200)->assertJson(['duplicate' => true]);

        $this->assertEquals(1, HrmsWebhookEvent::count());
    }

    public function test_same_event_id_from_a_different_company_is_not_a_duplicate(): void
    {
        // The unique index is per company: 'evt-1' from two tenants is two events.
        $this->send($this->payload(), company: $this->companyA, secret: $this->secretA)->assertStatus(202);
        $this->send($this->payload(), company: $this->companyB, secret: $this->secretB)->assertStatus(202);

        $this->assertEquals(2, HrmsWebhookEvent::count());
        $this->assertEquals(1, HrmsWebhookEvent::where('company_id', $this->companyA->id)->count());
        $this->assertEquals(1, HrmsWebhookEvent::where('company_id', $this->companyB->id)->count());
    }

    public function test_one_companys_secret_does_not_work_on_another_companys_url(): void
    {
        $this->send($this->payload(), company: $this->companyB, secret: $this->secretA)
            ->assertStatus(401);

        $this->assertEquals(0, HrmsWebhookEvent::count());
    }

    public function test_company_without_configured_secret_is_rejected(): void
    {
        config()->set("hrms.companies.{$this->companyA->id}", null);

        $this->send($this->payload())->assertStatus(401);
    }

    public function test_static_token_auth_mode_is_accepted(): void
    {
        $this->configureWebhook($this->companyA->id, $this->secretA, auth: 'token');

        $this->withHeaders(['Authorization' => "Bearer {$this->secretA}"])
            ->postJson("/api/hrms/{$this->companyA->code}/events", $this->payload())
            ->assertStatus(202);
    }

    public function test_wrong_static_token_is_rejected(): void
    {
        $this->configureWebhook($this->companyA->id, $this->secretA, auth: 'token');

        $this->withHeaders(['Authorization' => 'Bearer nope'])
            ->postJson("/api/hrms/{$this->companyA->code}/events", $this->payload())
            ->assertStatus(401);
    }

    public function test_payload_without_event_id_is_unprocessable(): void
    {
        $payload = $this->payload();
        unset($payload['event_id']);

        $this->send($payload)->assertStatus(422);
        $this->assertEquals(0, HrmsWebhookEvent::count());
    }

    public function test_empty_body_is_unprocessable(): void
    {
        $this->send([])->assertStatus(422);
        $this->assertEquals(0, HrmsWebhookEvent::count());
    }

    public function test_unknown_company_code_is_not_found(): void
    {
        $this->send($this->payload(), company: new Company(['code' => 'NOPE99']))
            ->assertStatus(404);
    }

    public function test_webhook_route_sits_in_the_api_group_not_the_web_group(): void
    {
        // The web group would put sessions, CSRF and EnsureMustChangePassword in
        // front of a caller that has none of them. That the signed requests above
        // return 202 rather than 419 is the functional proof; this pins the
        // wiring so a later refactor cannot quietly move the route.
        $middleware = $this->app['router']->getRoutes()
            ->getByName('api.hrms.events')
            ->middleware();

        $this->assertContains('api', $middleware);
        $this->assertContains('hrms.signature', $middleware);
        $this->assertContains('throttle:hrms-webhook', $middleware);
        $this->assertNotContains('web', $middleware);
    }
}
