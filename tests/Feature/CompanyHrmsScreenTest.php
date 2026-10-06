<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\HrmsWebhookEvent;
use App\Models\User;
use App\Services\Hrms\Adapters\GenericHrmsAdapter;
use App\Services\Hrms\Adapters\HrmsVendorAdapter;
use App\Services\Hrms\HrmsEventMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CompanyHrmsScreenTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $adminA;

    protected Employee $empA1;

    protected function setUp(): void
    {
        parent::setUp();

        // Pins url() so the asserted endpoint is deterministic.
        URL::forceRootUrl('http://mealbells.test');

        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);
        $this->companyB = Company::create(['name' => 'Beta Corp', 'code' => 'BETA1']);

        $this->adminA = User::create([
            'name' => 'Admin A', 'email' => 'admin@a.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyA->id,
        ]);

        CompanySetting::create([
            'company_id' => $this->companyA->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            'primary_admin_id' => $this->adminA->id,
        ]);

        $this->empA1 = Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP101', 'external_id' => 'HR-1',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    /** @return array<string, mixed> */
    protected function props()
    {
        $response = $this->actingAs($this->adminA)->get('/company-admin/hrms');
        $response->assertStatus(200);

        return $response->getOriginalContent()->getData()['page']['props'];
    }

    public function test_the_screen_shows_the_webhook_url_and_no_secret_yet(): void
    {
        $props = $this->props();

        $this->assertEquals('http://mealbells.test/api/hrms/ALPHA1/events', $props['connection']['webhook_url']);
        $this->assertFalse($props['connection']['has_secret']);
        $this->assertEquals('generic', $props['connection']['adapter']);
        $this->assertEmpty($props['events']);
    }

    public function test_generating_a_secret_shows_it_once_and_never_again(): void
    {
        $this->actingAs($this->adminA)->post('/company-admin/hrms/secret')->assertRedirect();

        $first = $this->props();
        $this->assertNotNull($first['new_secret']);
        $this->assertTrue($first['connection']['has_secret']);

        $secret = $first['new_secret'];

        // Flash is consumed, so a reload no longer carries the plaintext.
        $second = $this->props();
        $this->assertNull($second['new_secret']);
        $this->assertStringNotContainsString($secret, json_encode($second));
    }

    public function test_rotating_replaces_the_stored_secret(): void
    {
        $this->actingAs($this->adminA)->post('/company-admin/hrms/secret');
        $first = CompanyHrmsConnection::where('company_id', $this->companyA->id)->sole()->webhook_secret;

        $this->actingAs($this->adminA)->post('/company-admin/hrms/secret');
        $second = CompanyHrmsConnection::where('company_id', $this->companyA->id)->sole()->webhook_secret;

        $this->assertNotEquals($first, $second);
        $this->assertEquals(1, CompanyHrmsConnection::count());
    }

    public function test_a_company_admin_only_ever_sees_its_own_company(): void
    {
        CompanyHrmsConnection::create([
            'company_id' => $this->companyB->id, 'webhook_secret' => 'whsec_b', 'secret_rotated_at' => now(),
        ]);

        HrmsWebhookEvent::create([
            'company_id' => $this->companyB->id, 'external_event_id' => 'evt-b',
            'event_type' => 'leave_approved', 'payload' => [], 'status' => HrmsWebhookEvent::STATUS_APPLIED,
        ]);

        $props = $this->props();

        $this->assertEquals('ALPHA1', $props['company']['code']);
        $this->assertFalse($props['connection']['has_secret']);
        $this->assertEmpty($props['events']);
    }

    public function test_the_event_list_shows_status_and_the_days_that_moved(): void
    {
        HrmsWebhookEvent::create([
            'company_id' => $this->companyA->id,
            'external_event_id' => 'evt-1',
            'event_type' => 'leave_approved',
            'payload' => [],
            'status' => HrmsWebhookEvent::STATUS_APPLIED,
            'processed_at' => now(),
            'result' => [
                'applied_days' => ['2026-10-06'],
                'already_days' => [],
                'released_days' => ['2026-10-07'],
                'blocked_days' => [['date' => '2026-10-08', 'reason' => 'count_locked']],
                'release_blocked' => [['date' => '2026-10-09', 'reason' => 'cutoff_passed']],
                'notes' => [],
            ],
        ]);

        $event = $this->props()['events'][0];

        $this->assertEquals('applied', $event['status']);
        $this->assertEquals(['2026-10-06'], $event['applied_days']);
        $this->assertEquals(['2026-10-07'], $event['released_days']);

        // Both kinds of refusal are shown together, so a company admin sees every
        // day that did not move.
        $this->assertCount(2, $event['blocked_days']);
    }

    public function test_the_event_list_is_capped_at_twenty_newest_first(): void
    {
        foreach (range(1, 25) as $index) {
            HrmsWebhookEvent::create([
                'company_id' => $this->companyA->id,
                'external_event_id' => "evt-{$index}",
                'event_type' => 'leave_approved',
                'payload' => [],
                'status' => HrmsWebhookEvent::STATUS_APPLIED,
            ]);
        }

        $events = $this->props()['events'];

        $this->assertCount(20, $events);
        $this->assertEquals('evt-25', $events[0]['external_event_id']);
    }

    public function test_the_test_button_sends_a_signature_the_endpoint_would_accept(): void
    {
        $this->actingAs($this->adminA)->post('/company-admin/hrms/secret');
        $secret = CompanyHrmsConnection::where('company_id', $this->companyA->id)->sole()->webhook_secret;

        Http::fake(['*' => Http::response(['message' => 'Event accepted.'], 202)]);

        $this->actingAs($this->adminA)->post('/company-admin/hrms/test-event', [
            'event' => 'leave_approved',
            'employee' => 'HR-1',
            'from' => '2026-10-06',
        ])->assertRedirect();

        Http::assertSent(function ($request) use ($secret) {
            $timestamp = $request->header('X-Hrms-Timestamp')[0];

            return $request->url() === 'http://mealbells.test/api/hrms/ALPHA1/events'
                && $request->header('X-Hrms-Signature')[0]
                    === hash_hmac('sha256', $timestamp.'.'.$request->body(), $secret);
        });

        $result = session('test_result');
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['employee_matched']);
    }

    public function test_the_test_button_reports_an_unmatched_employee(): void
    {
        $this->actingAs($this->adminA)->post('/company-admin/hrms/secret');
        Http::fake(['*' => Http::response([], 202)]);

        $this->actingAs($this->adminA)->post('/company-admin/hrms/test-event', [
            'employee' => 'HR-NOBODY', 'from' => '2026-10-06',
        ])->assertRedirect();

        $result = session('test_result');
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['employee_matched']);
    }

    public function test_the_test_button_fails_cleanly_without_a_secret(): void
    {
        Http::fake();

        $this->actingAs($this->adminA)->post('/company-admin/hrms/test-event', [
            'employee' => 'HR-1',
        ])->assertRedirect();

        Http::assertNothingSent();

        $result = session('test_result');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No webhook secret', $result['error']);
    }

    public function test_an_employee_cannot_reach_the_hrms_screen(): void
    {
        $employeeUser = User::create([
            'name' => 'Emp', 'email' => 'emp@a.test', 'password' => bcrypt('password'),
            'role' => 'employee', 'company_id' => $this->companyA->id,
        ]);
        $this->empA1->update(['user_id' => $employeeUser->id]);

        $this->actingAs($employeeUser)->get('/company-admin/hrms')->assertStatus(403);
        $this->actingAs($employeeUser)->post('/company-admin/hrms/secret')->assertStatus(403);
    }

    public function test_the_generic_adapter_passes_a_payload_through_untouched(): void
    {
        $adapter = new GenericHrmsAdapter;

        $this->assertEquals('generic', $adapter->name());
        $this->assertEquals(['a' => 1, 'b' => ['c' => 2]], $adapter->toGeneric(['a' => 1, 'b' => ['c' => 2]]));
    }

    public function test_a_company_adapter_can_reshape_the_payload_before_mapping(): void
    {
        // Proves the seam: a vendor that wraps its event in an envelope config
        // cannot address is handled by an adapter, with no change to the mapper.
        $adapter = new class implements HrmsVendorAdapter
        {
            public function name(): string
            {
                return 'wrapped';
            }

            public function toGeneric(array $payload): array
            {
                return $payload['body'] ?? $payload;
            }
        };

        app()->instance('test.wrapped.adapter', $adapter);
        config()->set("hrms.companies.{$this->companyA->id}.adapter", 'test.wrapped.adapter');

        $plan = app(HrmsEventMapper::class)->map($this->companyA, [
            'body' => [
                'event_id' => 'evt-1',
                'event_type' => 'leave_approved',
                'leave' => [
                    'id' => 'L-1', 'employee_id' => 'HR-1',
                    'from_date' => '2026-10-06', 'to_date' => '2026-10-06',
                ],
            ],
        ]);

        $this->assertTrue($plan->shouldApply());
        $this->assertEquals($this->empA1->id, $plan->employee->id);
    }
}
