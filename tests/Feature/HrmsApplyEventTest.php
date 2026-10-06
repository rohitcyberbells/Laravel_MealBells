<?php

namespace Tests\Feature;

use App\Jobs\ProcessHrmsLeaveEvent;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\HrmsWebhookEvent;
use App\Models\MealCount;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use App\Services\Hrms\HrmsEventMapper;
use App\Services\Hrms\HrmsEventPlan;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Step 3 end to end: a signed delivery arrives, the job runs (the test queue is
 * sync), and skips actually move.
 */
class HrmsApplyEventTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected User $adminA;

    protected Employee $empA1;

    protected TiffinService $tiffin;

    protected string $secret = 'whsec_apply';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tiffin = TiffinService::create(['name' => 'Tiffin Co']);
        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        $this->adminA = User::create([
            'name' => 'Admin A', 'email' => 'admin@a.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyA->id,
        ]);

        CompanySetting::create([
            'company_id' => $this->companyA->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            'primary_admin_id' => $this->adminA->id,
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffin->id,
            'is_active' => true,
            'assigned_at' => '2026-09-01',
        ]);

        $this->empA1 = Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP101', 'external_id' => 'HR-1',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        config()->set("hrms.companies.{$this->companyA->id}", [
            'webhook' => ['auth' => 'signature', 'secret' => $this->secret],
        ]);
    }

    /** @return array<string, mixed> */
    protected function approval(
        string $eventId = 'evt-1',
        string $from = '2026-10-06',
        ?string $to = null,
        string $leaveId = 'L-1',
        string $employee = 'HR-1',
        string $eventType = 'leave_approved',
        string $occurredAt = '2026-10-05T03:00:00Z',
    ): array {
        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt,
            'leave' => [
                'id' => $leaveId,
                'employee_id' => $employee,
                'from_date' => $from,
                'to_date' => $to ?? $from,
                'reason' => 'Approved in HRMS',
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function cancellation(string $eventId = 'evt-c', string $leaveId = 'L-1', string $occurredAt = '2026-10-05T04:00:00Z'): array
    {
        return [
            'event_id' => $eventId,
            'event_type' => 'leave_cancelled',
            'occurred_at' => $occurredAt,
            'leave' => ['id' => $leaveId],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    protected function deliver(array $payload)
    {
        $body = json_encode($payload);
        $timestamp = (string) now()->timestamp;

        return $this->withHeaders([
            'X-Hrms-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $this->secret),
            'X-Hrms-Timestamp' => $timestamp,
        ])->postJson("/api/hrms/{$this->companyA->code}/events", $payload);
    }

    protected function eventFor(string $eventId): HrmsWebhookEvent
    {
        return HrmsWebhookEvent::where('external_event_id', $eventId)->sole();
    }

    public function test_approval_creates_the_skip_with_its_provenance(): void
    {
        $this->deliver($this->approval())->assertStatus(202);

        $skip = Skip::sole();
        $this->assertEquals($this->empA1->id, $skip->employee_id);
        $this->assertEquals('2026-10-06', Carbon::parse($skip->date)->toDateString());
        $this->assertEquals('leave', $skip->source);
        $this->assertEquals('L-1', $skip->external_ref);
        $this->assertNull($skip->cancelled_at);

        $event = $this->eventFor('evt-1');
        $this->assertEquals(HrmsWebhookEvent::STATUS_APPLIED, $event->status);
        $this->assertEquals(['2026-10-06'], $event->result['applied_days']);
        $this->assertNotNull($event->processed_at);
    }

    public function test_wfh_approval_uses_the_wfh_source(): void
    {
        $this->deliver($this->approval(eventType: 'wfh_approved'))->assertStatus(202);

        $this->assertEquals('wfh', Skip::sole()->source);
    }

    public function test_multi_day_approval_skips_the_weekend_and_records_the_counts(): void
    {
        // Fri 09 -> Mon 12 spans Sat 10 and Sun 11.
        $this->deliver($this->approval(from: '2026-10-09', to: '2026-10-12'))->assertStatus(202);

        $this->assertEquals(2, Skip::count());

        $result = $this->eventFor('evt-1')->result;
        $this->assertEquals(['2026-10-09', '2026-10-12'], $result['applied_days']);
        $this->assertEquals(['2026-10-10', '2026-10-11'], $result['non_meal_days']);
    }

    public function test_cancellation_releases_the_skips_the_leave_created(): void
    {
        $this->deliver($this->approval())->assertStatus(202);
        $this->deliver($this->cancellation())->assertStatus(202);

        $skip = Skip::sole();
        $this->assertNotNull($skip->cancelled_at);
        $this->assertEquals($this->adminA->id, $skip->cancelled_by);

        $event = $this->eventFor('evt-c');
        $this->assertEquals(HrmsWebhookEvent::STATUS_APPLIED, $event->status);
        $this->assertEquals(['2026-10-06'], $event->result['released_days']);
    }

    public function test_shortened_leave_releases_only_the_days_it_no_longer_covers(): void
    {
        // Mon 05 -> Wed 07 approved.
        $this->deliver($this->approval(from: '2026-10-05', to: '2026-10-07'))->assertStatus(202);
        $this->assertEquals(3, Skip::whereNull('cancelled_at')->count());

        // Re-sent as Mon 05 only, later than the first.
        $this->deliver($this->approval(
            eventId: 'evt-2', from: '2026-10-05', to: '2026-10-05', occurredAt: '2026-10-05T05:00:00Z'
        ))->assertStatus(202);

        $this->assertEquals(1, Skip::whereNull('cancelled_at')->count());
        $this->assertEquals('2026-10-05', Carbon::parse(Skip::whereNull('cancelled_at')->sole()->date)->toDateString());

        $result = $this->eventFor('evt-2')->result;
        $this->assertEqualsCanonicalizing(['2026-10-06', '2026-10-07'], $result['released_days']);
    }

    public function test_event_after_the_cutoff_is_blocked_and_no_skip_is_created(): void
    {
        // Cutoff is 11:00; it is now 12:00 and the leave is for today.
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'Asia/Kolkata'));

        $this->deliver($this->approval(from: '2026-10-05'))->assertStatus(202);

        $this->assertEquals(0, Skip::count());

        $event = $this->eventFor('evt-1');
        $this->assertEquals(HrmsWebhookEvent::STATUS_BLOCKED, $event->status);
        $this->assertEquals('cutoff_passed', $event->result['blocked_days'][0]['reason']);
    }

    public function test_locked_day_is_blocked_while_the_other_days_still_apply(): void
    {
        MealCount::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffin->id,
            'date' => '2026-10-06',
            'base_eligible_count' => 1, 'skip_count' => 0, 'extra_count' => 0,
            'final_expected_count' => 1, 'breakdown' => [],
            'status' => 'auto_confirmed', 'locked_at' => now(),
        ]);

        $this->deliver($this->approval(from: '2026-10-06', to: '2026-10-07'))->assertStatus(202);

        $event = $this->eventFor('evt-1');
        $this->assertEquals(HrmsWebhookEvent::STATUS_APPLIED, $event->status);
        $this->assertEquals(['2026-10-07'], $event->result['applied_days']);
        $this->assertEquals('count_locked', $event->result['blocked_days'][0]['reason']);
        $this->assertEquals('2026-10-06', $event->result['blocked_days'][0]['date']);
    }

    public function test_locked_day_blocks_only_its_own_release(): void
    {
        $this->deliver($this->approval(from: '2026-10-06', to: '2026-10-07'))->assertStatus(202);
        $this->assertEquals(2, Skip::whereNull('cancelled_at')->count());

        // The count for 10-06 is snapshotted after the skips were created.
        MealCount::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffin->id,
            'date' => '2026-10-06',
            'base_eligible_count' => 1, 'skip_count' => 1, 'extra_count' => 0,
            'final_expected_count' => 0, 'breakdown' => [],
            'status' => 'auto_confirmed', 'locked_at' => now(),
        ]);

        $this->deliver($this->cancellation(occurredAt: '2026-10-05T06:00:00Z'))->assertStatus(202);

        $event = $this->eventFor('evt-c');

        // One day released, the locked one logged - and no crash.
        $this->assertEquals(HrmsWebhookEvent::STATUS_APPLIED, $event->status);
        $this->assertEquals(['2026-10-07'], $event->result['released_days']);
        $this->assertEquals(
            [['date' => '2026-10-06', 'reason' => 'count_locked']],
            $event->result['release_blocked']
        );

        // The locked day's skip is still standing, because the vendor has no
        // right to change a count the company already committed to.
        $this->assertNull(Skip::where('date', '2026-10-06')->sole()->cancelled_at);
        $this->assertNotNull(Skip::where('date', '2026-10-07')->sole()->cancelled_at);
    }

    public function test_release_after_the_cutoff_is_blocked_and_does_not_crash(): void
    {
        // Created while the cutoff was still open.
        $this->deliver($this->approval(from: '2026-10-05'))->assertStatus(202);
        $this->assertEquals(1, Skip::whereNull('cancelled_at')->count());

        // The leave is revoked after the 11:00 cutoff has passed.
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'Asia/Kolkata'));

        $this->deliver($this->cancellation(occurredAt: '2026-10-05T07:00:00Z'))->assertStatus(202);

        $event = $this->eventFor('evt-c');
        $this->assertEquals(HrmsWebhookEvent::STATUS_BLOCKED, $event->status);
        $this->assertEmpty($event->result['released_days']);
        $this->assertEquals(
            [['date' => '2026-10-05', 'reason' => 'cutoff_passed']],
            $event->result['release_blocked']
        );

        $this->assertNull(Skip::sole()->cancelled_at);
    }

    public function test_release_is_blocked_when_the_company_has_no_admin_to_attribute_it_to(): void
    {
        $this->deliver($this->approval())->assertStatus(202);

        // Remove every admin, so CancelSkip has no actor.
        CompanySetting::where('company_id', $this->companyA->id)->update(['primary_admin_id' => null]);
        User::where('company_id', $this->companyA->id)->delete();

        $this->deliver($this->cancellation(occurredAt: '2026-10-05T06:00:00Z'))->assertStatus(202);

        $event = $this->eventFor('evt-c');
        $this->assertEquals(HrmsWebhookEvent::STATUS_BLOCKED, $event->status);
        $this->assertEquals('no_actor', $event->result['release_blocked'][0]['reason']);
        $this->assertNull(Skip::sole()->cancelled_at);
    }

    public function test_unknown_employee_is_blocked_not_failed(): void
    {
        $this->deliver($this->approval(employee: 'HR-NOBODY'))->assertStatus(202);

        $this->assertEquals(0, Skip::count());

        $event = $this->eventFor('evt-1');
        $this->assertEquals(HrmsWebhookEvent::STATUS_BLOCKED, $event->status);
        $this->assertNotEquals(HrmsWebhookEvent::STATUS_FAILED, $event->status);
    }

    public function test_inactive_employee_is_blocked(): void
    {
        $this->empA1->update(['status' => 'inactive']);

        $this->deliver($this->approval())->assertStatus(202);

        $this->assertEquals(0, Skip::count());
        $this->assertEquals(HrmsWebhookEvent::STATUS_BLOCKED, $this->eventFor('evt-1')->status);
    }

    public function test_out_of_order_approval_behind_a_newer_cancellation_is_stale(): void
    {
        // Cancellation happened at 04:00 and is delivered first.
        $this->deliver($this->cancellation(occurredAt: '2026-10-05T04:00:00Z'))->assertStatus(202);

        // The older approval (03:00) arrives afterwards.
        $this->deliver($this->approval(occurredAt: '2026-10-05T03:00:00Z'))->assertStatus(202);

        $this->assertEquals(0, Skip::count());
        $this->assertEquals(HrmsWebhookEvent::STATUS_STALE, $this->eventFor('evt-1')->status);
    }

    public function test_cancellation_never_touches_a_skip_hr_entered_by_hand(): void
    {
        $manual = Skip::create([
            'company_id' => $this->companyA->id,
            'employee_id' => $this->empA1->id,
            'date' => '2026-10-06',
            'source' => 'leave',
            'reason' => 'HR entered by hand',
            'external_ref' => null,
        ]);

        $this->deliver($this->cancellation())->assertStatus(202);

        $this->assertNull($manual->fresh()->cancelled_at);
        $this->assertEquals(HrmsWebhookEvent::STATUS_IGNORED, $this->eventFor('evt-c')->status);
    }

    public function test_approval_does_not_override_an_existing_hr_skip(): void
    {
        Skip::create([
            'company_id' => $this->companyA->id,
            'employee_id' => $this->empA1->id,
            'date' => '2026-10-06',
            'source' => 'hr',
            'reason' => 'HR decided',
        ]);

        $this->deliver($this->approval())->assertStatus(202);

        // First source wins: still HR's, and still without an HRMS reference.
        $skip = Skip::sole();
        $this->assertEquals('hr', $skip->source);
        $this->assertNull($skip->external_ref);

        $event = $this->eventFor('evt-1');
        $this->assertEquals(HrmsWebhookEvent::STATUS_APPLIED, $event->status);
        $this->assertEquals(['2026-10-06'], $event->result['already_days']);
    }

    public function test_deliberately_cancelled_skip_is_not_resurrected(): void
    {
        $this->deliver($this->approval())->assertStatus(202);

        Skip::sole()->update(['cancelled_at' => now(), 'cancelled_by' => $this->adminA->id]);

        // The HRMS re-sends the same approval.
        $this->deliver($this->approval(eventId: 'evt-2', occurredAt: '2026-10-05T03:30:00Z'))->assertStatus(202);

        $this->assertNotNull(Skip::sole()->cancelled_at);
        $this->assertEquals('blocked_cancelled', $this->eventFor('evt-2')->result['blocked_days'][0]['reason']);
    }

    public function test_leave_falling_entirely_on_a_weekend_is_ignored(): void
    {
        $this->deliver($this->approval(from: '2026-10-10', to: '2026-10-11'))->assertStatus(202);

        $this->assertEquals(0, Skip::count());
        $this->assertEquals(HrmsWebhookEvent::STATUS_IGNORED, $this->eventFor('evt-1')->status);
    }

    public function test_transient_error_marks_the_event_failed_and_rethrows_for_retry(): void
    {
        $event = HrmsWebhookEvent::create([
            'company_id' => $this->companyA->id,
            'external_event_id' => 'evt-boom',
            'event_type' => 'leave_approved',
            'leave_external_id' => 'L-1',
            'occurred_at' => '2026-10-05 03:00:00',
            'payload' => $this->approval(),
            'status' => HrmsWebhookEvent::STATUS_RECEIVED,
        ]);

        $this->app->bind(HrmsEventMapper::class, fn () => new class extends HrmsEventMapper
        {
            public function __construct() {}

            public function map(Company $company, array $payload): HrmsEventPlan
            {
                throw new RuntimeException('Database has gone away');
            }
        });

        try {
            $this->app->call([new ProcessHrmsLeaveEvent($event->id), 'handle']);
            $this->fail('Expected the transient error to be rethrown for the queue.');
        } catch (RuntimeException $e) {
            $this->assertEquals('Database has gone away', $e->getMessage());
        }

        $event->refresh();
        $this->assertEquals(HrmsWebhookEvent::STATUS_FAILED, $event->status);
        $this->assertEquals('Database has gone away', $event->error);
        $this->assertEquals(0, Skip::count());
    }

    public function test_a_failed_event_is_picked_back_up_on_retry(): void
    {
        $event = HrmsWebhookEvent::create([
            'company_id' => $this->companyA->id,
            'external_event_id' => 'evt-retry',
            'event_type' => 'leave_approved',
            'leave_external_id' => 'L-1',
            'occurred_at' => '2026-10-05 03:00:00',
            'payload' => $this->approval(),
            'status' => HrmsWebhookEvent::STATUS_FAILED,
            'error' => 'Database has gone away',
        ]);

        $this->app->call([new ProcessHrmsLeaveEvent($event->id), 'handle']);

        $event->refresh();
        $this->assertEquals(HrmsWebhookEvent::STATUS_APPLIED, $event->status);
        $this->assertNull($event->error);
        $this->assertEquals(1, Skip::count());
    }

    public function test_an_already_applied_event_is_not_processed_twice(): void
    {
        $this->deliver($this->approval())->assertStatus(202);
        $event = $this->eventFor('evt-1');

        // Re-running the job must be a no-op rather than a second apply.
        $this->app->call([new ProcessHrmsLeaveEvent($event->id), 'handle']);

        $event->refresh();
        $this->assertEquals(1, Skip::count());
        $this->assertEquals(HrmsWebhookEvent::STATUS_APPLIED, $event->status);

        // Counting skips alone would not prove anything, because RecordSkip is
        // idempotent. A second pass would have reported the day as already
        // skipped instead of newly applied, so the original result standing
        // unchanged is what shows the status guard returned early.
        $this->assertEquals(['2026-10-06'], $event->result['applied_days']);
        $this->assertEmpty($event->result['already_days']);
    }
}
