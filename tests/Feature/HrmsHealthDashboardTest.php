<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HrmsWebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrmsHealthDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        $this->superAdmin = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test', 'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);
    }

    protected function event(string $status, ?string $createdAt = null, array $overrides = []): HrmsWebhookEvent
    {
        $event = HrmsWebhookEvent::create(array_merge([
            'company_id' => $this->companyA->id,
            'external_event_id' => 'evt-'.uniqid(),
            'event_type' => 'leave_approved',
            'leave_external_id' => 'L-1',
            'payload' => ['event_id' => 'x'],
            'status' => $status,
        ], $overrides));

        if ($createdAt) {
            $event->forceFill(['created_at' => $createdAt])->save();
        }

        return $event;
    }

    /** @return array<string, mixed> */
    protected function hrmsProps(): array
    {
        $response = $this->actingAs($this->superAdmin)->get('/super-admin/health');
        $response->assertStatus(200);

        return $response->getOriginalContent()->getData()['page']['props']['hrms'];
    }

    public function test_health_page_reports_zero_when_no_events_exist(): void
    {
        $hrms = $this->hrmsProps();

        $this->assertEquals(0, $hrms['total_events']);
        $this->assertEquals(0, $hrms['stuck_count']);
        $this->assertNull($hrms['last_event_at']);
        $this->assertEquals(0, $hrms['today']['failed']);
    }

    public function test_it_counts_today_separately_from_the_last_seven_days(): void
    {
        $this->event(HrmsWebhookEvent::STATUS_FAILED);
        $this->event(HrmsWebhookEvent::STATUS_BLOCKED);
        $this->event(HrmsWebhookEvent::STATUS_STALE);

        // Three days back: inside the 7 day window, outside today.
        $this->event(HrmsWebhookEvent::STATUS_FAILED, now()->subDays(3)->toDateTimeString());

        // Ten days back: outside both windows.
        $this->event(HrmsWebhookEvent::STATUS_FAILED, now()->subDays(10)->toDateTimeString());

        $hrms = $this->hrmsProps();

        $this->assertEquals(1, $hrms['today']['failed']);
        $this->assertEquals(1, $hrms['today']['blocked']);
        $this->assertEquals(1, $hrms['today']['stale']);

        $this->assertEquals(2, $hrms['last_7_days']['failed']);
        $this->assertEquals(5, $hrms['total_events']);
    }

    public function test_it_flags_events_stuck_past_the_reconcile_window(): void
    {
        $this->event(HrmsWebhookEvent::STATUS_RECEIVED, now()->subMinutes(45)->toDateTimeString());

        // Just accepted, so not stuck yet.
        $this->event(HrmsWebhookEvent::STATUS_RECEIVED);

        $hrms = $this->hrmsProps();

        $this->assertEquals(1, $hrms['stuck_count']);
        $this->assertEquals('Alpha Corp', $hrms['stuck_events'][0]['company_name']);
        $this->assertEquals(45, $hrms['stuck_events'][0]['minutes_waiting']);
    }

    public function test_it_counts_failed_events_the_backstop_gave_up_on(): void
    {
        $this->event(HrmsWebhookEvent::STATUS_FAILED, null, ['reconcile_attempts' => 3]);
        $this->event(HrmsWebhookEvent::STATUS_FAILED, null, ['reconcile_attempts' => 1]);

        $this->assertEquals(1, $this->hrmsProps()['abandoned_count']);
    }

    public function test_it_reports_the_last_event_time(): void
    {
        $this->event(HrmsWebhookEvent::STATUS_APPLIED, now()->subDays(2)->toDateTimeString());
        $this->event(HrmsWebhookEvent::STATUS_APPLIED);

        $this->assertEquals(now()->toDateTimeString(), $this->hrmsProps()['last_event_at']);
    }

    public function test_a_company_admin_cannot_reach_the_health_page(): void
    {
        $companyAdmin = User::create([
            'name' => 'Admin A', 'email' => 'admin@a.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyA->id,
        ]);

        $this->actingAs($companyAdmin)->get('/super-admin/health')->assertStatus(403);
    }
}
