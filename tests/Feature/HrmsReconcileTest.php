<?php

namespace Tests\Feature;

use App\Jobs\ProcessHrmsLeaveEvent;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\HrmsWebhookEvent;
use App\Models\Skip;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HrmsReconcileTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Employee $empA1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        $admin = User::create([
            'name' => 'Admin A', 'email' => 'admin@a.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyA->id,
        ]);

        CompanySetting::create([
            'company_id' => $this->companyA->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            'primary_admin_id' => $admin->id,
        ]);

        $this->empA1 = Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP101', 'external_id' => 'HR-1',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    /** @return array<string, mixed> */
    protected function payload(string $eventId = 'evt-1', string $leaveId = 'L-1'): array
    {
        return [
            'event_id' => $eventId,
            'event_type' => 'leave_approved',
            'occurred_at' => '2026-10-05T03:00:00Z',
            'leave' => [
                'id' => $leaveId,
                'employee_id' => 'HR-1',
                'from_date' => '2026-10-06',
                'to_date' => '2026-10-06',
            ],
        ];
    }

    protected function event(string $status, array $overrides = []): HrmsWebhookEvent
    {
        return HrmsWebhookEvent::create(array_merge([
            'company_id' => $this->companyA->id,
            'external_event_id' => 'evt-'.uniqid(),
            'event_type' => 'leave_approved',
            'leave_external_id' => 'L-1',
            'occurred_at' => '2026-10-05 03:00:00',
            'payload' => $this->payload(),
            'status' => $status,
        ], $overrides));
    }

    public function test_an_event_stuck_in_received_is_reprocessed(): void
    {
        $event = $this->event(HrmsWebhookEvent::STATUS_RECEIVED);
        $event->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $this->artisan('hrms:reconcile')->assertExitCode(0);

        $event->refresh();
        $this->assertEquals(HrmsWebhookEvent::STATUS_APPLIED, $event->status);
        $this->assertEquals(1, $event->reconcile_attempts);
        $this->assertNotNull($event->last_reconciled_at);
        $this->assertEquals(1, Skip::count());
    }

    public function test_a_recent_event_is_left_alone(): void
    {
        Queue::fake();

        // Created just now, so still inside the reconcile window.
        $this->event(HrmsWebhookEvent::STATUS_RECEIVED);

        $this->artisan('hrms:reconcile')->expectsOutput('Nothing to reconcile.')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_running_twice_does_not_create_a_second_skip(): void
    {
        $event = $this->event(HrmsWebhookEvent::STATUS_RECEIVED);
        $event->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $this->artisan('hrms:reconcile')->assertExitCode(0);
        $this->artisan('hrms:reconcile')->assertExitCode(0);

        $this->assertEquals(1, Skip::count());

        // The second pass found nothing: the event is applied, so it no longer
        // matches either query, and the attempt counter stands still.
        $this->assertEquals(1, $event->fresh()->reconcile_attempts);
    }

    public function test_a_failed_event_is_retried(): void
    {
        $event = $this->event(HrmsWebhookEvent::STATUS_FAILED, ['error' => 'Database has gone away']);

        $this->artisan('hrms:reconcile')->assertExitCode(0);

        $event->refresh();
        $this->assertEquals(HrmsWebhookEvent::STATUS_APPLIED, $event->status);
        $this->assertNull($event->error);
        $this->assertEquals(1, Skip::count());
    }

    public function test_a_failed_event_stops_being_retried_after_the_limit(): void
    {
        Queue::fake();

        $event = $this->event(HrmsWebhookEvent::STATUS_FAILED, [
            'error' => 'Database has gone away',
            'reconcile_attempts' => 3,
        ]);

        $this->artisan('hrms:reconcile')->expectsOutput('Nothing to reconcile.')->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertEquals(3, $event->fresh()->reconcile_attempts);
    }

    public function test_the_cooldown_stops_a_second_redispatch_within_the_window(): void
    {
        Queue::fake();

        $event = $this->event(HrmsWebhookEvent::STATUS_FAILED, ['error' => 'boom']);

        $this->artisan('hrms:reconcile')->assertExitCode(0);
        Queue::assertPushed(ProcessHrmsLeaveEvent::class, 1);

        // Same minute, so the per-event cooldown must hold even though the
        // scheduler runs every five minutes.
        $this->artisan('hrms:reconcile')->assertExitCode(0);
        Queue::assertPushed(ProcessHrmsLeaveEvent::class, 1);

        $this->assertEquals(1, $event->fresh()->reconcile_attempts);
    }

    public function test_blocked_and_stale_events_are_never_retried(): void
    {
        Queue::fake();

        $this->event(HrmsWebhookEvent::STATUS_BLOCKED)->forceFill(['created_at' => now()->subDay()])->save();
        $this->event(HrmsWebhookEvent::STATUS_STALE)->forceFill(['created_at' => now()->subDay()])->save();
        $this->event(HrmsWebhookEvent::STATUS_IGNORED)->forceFill(['created_at' => now()->subDay()])->save();

        $this->artisan('hrms:reconcile')->expectsOutput('Nothing to reconcile.')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_received_events_are_not_capped_by_the_retry_limit(): void
    {
        Queue::fake();

        // A stopped queue worker must not cause the event to be abandoned: its
        // job has never run, so there is nothing to give up on.
        $event = $this->event(HrmsWebhookEvent::STATUS_RECEIVED, ['reconcile_attempts' => 9]);
        $event->forceFill(['created_at' => now()->subHour()])->save();

        $this->artisan('hrms:reconcile')->assertExitCode(0);

        Queue::assertPushed(ProcessHrmsLeaveEvent::class, 1);
        $this->assertEquals(10, $event->fresh()->reconcile_attempts);
    }

    public function test_dry_run_queues_nothing_and_records_no_attempt(): void
    {
        Queue::fake();

        $event = $this->event(HrmsWebhookEvent::STATUS_FAILED, ['error' => 'boom']);

        $this->artisan('hrms:reconcile', ['--dry-run' => true])->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertEquals(0, $event->fresh()->reconcile_attempts);
        $this->assertNull($event->fresh()->last_reconciled_at);
    }

    public function test_it_is_scheduled_every_five_minutes_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'hrms:reconcile'));

        $this->assertNotNull($event, 'hrms:reconcile is not scheduled.');
        $this->assertEquals('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }
}
