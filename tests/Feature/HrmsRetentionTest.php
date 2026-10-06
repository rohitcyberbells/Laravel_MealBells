<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HrmsWebhookEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrmsRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);
    }

    protected function event(string $createdAt, array $overrides = []): HrmsWebhookEvent
    {
        $event = HrmsWebhookEvent::create(array_merge([
            'company_id' => $this->companyA->id,
            'external_event_id' => 'evt-'.uniqid(),
            'event_type' => 'leave_approved',
            'leave_external_id' => 'L-1',
            'payload' => [
                'event_id' => 'evt-x',
                'leave' => ['employee_id' => 'HR-1', 'reason' => 'Medical appointment'],
            ],
            'status' => HrmsWebhookEvent::STATUS_APPLIED,
            'result' => ['applied_days' => ['2026-10-06'], 'blocked_days' => []],
        ], $overrides));

        $event->forceFill(['created_at' => $createdAt])->save();

        return $event->fresh();
    }

    public function test_an_old_payload_is_redacted_but_the_row_and_audit_trail_survive(): void
    {
        $event = $this->event(now()->subDays(45)->toDateTimeString());

        $this->artisan('model:prune', ['--model' => [HrmsWebhookEvent::class]])->assertExitCode(0);

        $event->refresh();

        // The PII is gone.
        $this->assertNull($event->payload);

        // What the integration actually did is not.
        $this->assertEquals(1, HrmsWebhookEvent::count());
        $this->assertEquals(HrmsWebhookEvent::STATUS_APPLIED, $event->status);
        $this->assertEquals(['2026-10-06'], $event->result['applied_days']);
        $this->assertEquals('L-1', $event->leave_external_id);
    }

    public function test_a_recent_payload_is_left_alone(): void
    {
        $event = $this->event(now()->subDays(5)->toDateTimeString());

        $this->artisan('model:prune', ['--model' => [HrmsWebhookEvent::class]])->assertExitCode(0);

        $this->assertNotNull($event->refresh()->payload);
        $this->assertEquals('Medical appointment', $event->payload['leave']['reason']);
    }

    public function test_the_boundary_respects_the_configured_window(): void
    {
        config()->set('hrms.retention.payload_days', 7);

        $old = $this->event(now()->subDays(8)->toDateTimeString());
        $fresh = $this->event(now()->subDays(6)->toDateTimeString());

        $this->artisan('model:prune', ['--model' => [HrmsWebhookEvent::class]])->assertExitCode(0);

        $this->assertNull($old->refresh()->payload);
        $this->assertNotNull($fresh->refresh()->payload);
    }

    public function test_an_already_redacted_event_is_not_picked_up_again(): void
    {
        $this->event(now()->subDays(45)->toDateTimeString(), ['payload' => null]);

        // Nothing left to redact, so the prunable query must match nothing.
        $this->assertEquals(0, (new HrmsWebhookEvent)->prunable()->count());
    }

    public function test_pruning_never_deletes_rows(): void
    {
        $this->event(now()->subDays(100)->toDateTimeString());
        $this->event(now()->subDays(60)->toDateTimeString());
        $this->event(now()->toDateTimeString());

        $this->artisan('model:prune', ['--model' => [HrmsWebhookEvent::class]])->assertExitCode(0);

        $this->assertEquals(3, HrmsWebhookEvent::count());
        $this->assertEquals(2, HrmsWebhookEvent::whereNull('payload')->count());
    }

    public function test_prune_is_scheduled_daily_for_this_model(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'model:prune'));

        $this->assertNotNull($event, 'model:prune is not scheduled.');
        $this->assertStringContainsString('HrmsWebhookEvent', $event->command);
        $this->assertEquals('0 3 * * *', $event->expression);
    }
}
