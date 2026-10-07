<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyCalendarDay;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\Skip;
use App\Models\User;
use App\Services\Hrms\HrmsActorResolver;
use App\Services\Hrms\HrmsEventMapper;
use App\Services\Hrms\HrmsEventPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 2 is mapping only: the mapper never writes, so every test asserts on the
 * returned plan and on the skips table being untouched.
 */
class HrmsEventMapperTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $primaryAdminA;

    protected User $otherAdminA;

    protected Employee $empA1;

    protected Employee $empA2;

    protected Employee $empB1;

    protected HrmsEventMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);
        $this->companyB = Company::create(['name' => 'Beta Corp', 'code' => 'BETA1']);

        $this->primaryAdminA = User::create([
            'name' => 'Primary A', 'email' => 'primary@a.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyA->id,
        ]);

        $this->otherAdminA = User::create([
            'name' => 'Other A', 'email' => 'other@a.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyA->id,
        ]);

        CompanySetting::create([
            'company_id' => $this->companyA->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            // Deliberately the second admin, so a test can tell 'primary admin'
            // apart from 'first admin by id'.
            'primary_admin_id' => $this->otherAdminA->id,
        ]);

        CompanySetting::create([
            'company_id' => $this->companyB->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => false, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->empA1 = Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP101', 'external_id' => 'HR-1',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->empA2 = Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP102', 'external_id' => null,
            'name' => 'Bob', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->empB1 = Employee::create([
            'company_id' => $this->companyB->id, 'employee_code' => 'EMP201', 'external_id' => 'HR-9',
            'name' => 'Carol', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->mapper = new HrmsEventMapper(new HrmsActorResolver);
    }

    /** @return array<string, mixed> */
    protected function approval(
        string $employee = 'HR-1',
        string $from = '2026-10-06',
        ?string $to = null,
        string $eventType = 'leave_approved',
        string $leaveId = 'L-1',
        ?string $leaveType = null,
    ): array {
        return [
            'event_id' => 'evt-1',
            'event_type' => $eventType,
            'occurred_at' => '2026-10-05T02:30:00Z',
            'leave' => array_filter([
                'id' => $leaveId,
                'employee_id' => $employee,
                'from_date' => $from,
                'to_date' => $to ?? $from,
                'type' => $leaveType,
                'reason' => 'Approved in HRMS',
            ], fn ($v) => $v !== null),
        ];
    }

    /** @return array<string, mixed> */
    protected function cancellation(string $leaveId = 'L-1'): array
    {
        return [
            'event_id' => 'evt-2',
            'event_type' => 'leave_cancelled',
            'occurred_at' => '2026-10-05T03:00:00Z',
            'leave' => ['id' => $leaveId],
        ];
    }

    protected function seedSkip(string $date, ?string $externalRef, ?Employee $employee = null, string $source = 'leave'): Skip
    {
        return Skip::create([
            'company_id' => $this->companyA->id,
            'employee_id' => ($employee ?? $this->empA1)->id,
            'date' => $date,
            'source' => $source,
            'external_ref' => $externalRef,
        ]);
    }

    public function test_single_day_approval_maps_to_a_clean_plan(): void
    {
        $plan = $this->mapper->map($this->companyA, $this->approval());

        $this->assertTrue($plan->shouldApply());
        $this->assertTrue($plan->isApproval());
        $this->assertEquals($this->empA1->id, $plan->employee->id);
        $this->assertEquals('L-1', $plan->leaveExternalId);
        $this->assertEquals('leave', $plan->source);
        $this->assertEquals('Approved in HRMS', $plan->reason);
        $this->assertEquals(['2026-10-06'], $plan->createDates);
        $this->assertEquals('2026-10-05 02:30:00', $plan->occurredAt->utc()->toDateTimeString());
        $this->assertTrue($plan->releaseSkips()->isEmpty());

        // Mapping writes nothing.
        $this->assertEquals(0, Skip::count());
    }

    public function test_unknown_employee_is_reported_and_not_applied(): void
    {
        $plan = $this->mapper->map($this->companyA, $this->approval(employee: 'HR-DOES-NOT-EXIST'));

        $this->assertEquals(HrmsEventPlan::UNKNOWN_EMPLOYEE, $plan->resolution);
        $this->assertFalse($plan->shouldApply());
        $this->assertStringContainsString('HR-DOES-NOT-EXIST', $plan->note);
    }

    public function test_employee_resolves_by_external_id_first(): void
    {
        $plan = $this->mapper->map($this->companyA, $this->approval(employee: 'HR-1'));

        $this->assertEquals($this->empA1->id, $plan->employee->id);
    }

    public function test_employee_falls_back_to_employee_code_case_insensitively(): void
    {
        $plan = $this->mapper->map($this->companyA, $this->approval(employee: 'emp102'));

        $this->assertTrue($plan->shouldApply());
        $this->assertEquals($this->empA2->id, $plan->employee->id);
    }

    public function test_another_companys_employee_reference_is_unknown_here(): void
    {
        // HR-9 is company B's employee; company A's mapper must not reach it.
        $plan = $this->mapper->map($this->companyA, $this->approval(employee: 'HR-9'));

        $this->assertEquals(HrmsEventPlan::UNKNOWN_EMPLOYEE, $plan->resolution);
    }

    public function test_multi_day_range_expands_to_each_meal_day(): void
    {
        // Mon 05 -> Wed 07.
        $plan = $this->mapper->map($this->companyA, $this->approval(from: '2026-10-05', to: '2026-10-07'));

        $this->assertEquals(['2026-10-05', '2026-10-06', '2026-10-07'], $plan->createDates);
    }

    public function test_weekend_days_are_dropped_from_the_range(): void
    {
        // Fri 09 -> Mon 12 spans Sat 10 and Sun 11.
        $plan = $this->mapper->map($this->companyA, $this->approval(from: '2026-10-09', to: '2026-10-12'));

        $this->assertEquals(['2026-10-09', '2026-10-12'], $plan->createDates);
        $this->assertEquals(['2026-10-10', '2026-10-11'], $plan->nonMealDays);
    }

    public function test_declared_holiday_is_dropped_from_the_range(): void
    {
        CompanyCalendarDay::create([
            'company_id' => $this->companyA->id,
            'date' => '2026-10-07',
            'type' => 'holiday',
            'note' => 'Festival',
        ]);

        $plan = $this->mapper->map($this->companyA, $this->approval(from: '2026-10-06', to: '2026-10-08'));

        $this->assertEquals(['2026-10-06', '2026-10-08'], $plan->createDates);
        $this->assertEquals(['2026-10-07'], $plan->nonMealDays);
    }

    public function test_dates_outside_the_editable_window_are_reported_separately(): void
    {
        // Frozen clock is Mon 2026-10-05, so 10-02 is already past.
        $plan = $this->mapper->map($this->companyA, $this->approval(from: '2026-10-02', to: '2026-10-06'));

        $this->assertEquals(['2026-10-05', '2026-10-06'], $plan->createDates);
        $this->assertContains('2026-10-02', $plan->outsideWindowDates);
    }

    public function test_wfh_approval_is_mapped_when_the_company_enabled_auto_skip(): void
    {
        $plan = $this->mapper->map($this->companyA, $this->approval(eventType: 'wfh_approved'));

        $this->assertTrue($plan->shouldApply());
        $this->assertEquals('wfh', $plan->source);
    }

    public function test_wfh_approval_is_ignored_when_the_company_disabled_auto_skip(): void
    {
        Employee::create([
            'company_id' => $this->companyB->id, 'employee_code' => 'EMP202', 'external_id' => 'HR-10',
            'name' => 'Dan', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $plan = $this->mapper->map($this->companyB, $this->approval(employee: 'HR-10', eventType: 'wfh_approved'));

        $this->assertEquals(HrmsEventPlan::IGNORED, $plan->resolution);
        $this->assertFalse($plan->shouldApply());
        $this->assertStringContainsString('WFH auto-skip is disabled', $plan->note);
    }

    public function test_unmapped_event_type_is_unmappable(): void
    {
        $plan = $this->mapper->map($this->companyA, $this->approval(eventType: 'sabbatical_granted'));

        $this->assertEquals(HrmsEventPlan::UNMAPPABLE, $plan->resolution);
    }

    /**
     * A skip removes a whole day's meal, so a half day is neither a skip nor a
     * non-skip. Recorded as ignored with a reason rather than guessed at.
     */
    public function test_a_half_day_leave_is_ignored_rather_than_guessed_at(): void
    {
        $plan = $this->mapper->map($this->companyA, $this->approval(leaveType: 'half-day'));

        $this->assertEquals(HrmsEventPlan::IGNORED, $plan->resolution);
        $this->assertStringContainsString('partial_day_not_supported', (string) $plan->note);
        $this->assertEquals('L-1', $plan->leaveExternalId);
        $this->assertEmpty($plan->createDates);
        $this->assertDatabaseCount('skips', 0);
    }

    public function test_a_short_leave_is_ignored_too(): void
    {
        $plan = $this->mapper->map($this->companyA, $this->approval(leaveType: 'short-leave'));

        $this->assertEquals(HrmsEventPlan::IGNORED, $plan->resolution);
        $this->assertStringContainsString('partial_day_not_supported', (string) $plan->note);
    }

    public function test_partial_day_matching_ignores_case_and_padding(): void
    {
        $plan = $this->mapper->map($this->companyA, $this->approval(leaveType: '  Half-Day '));

        $this->assertEquals(HrmsEventPlan::IGNORED, $plan->resolution);
        $this->assertStringContainsString('partial_day_not_supported', (string) $plan->note);
    }

    /**
     * The event name says leave_approved, which on its own would determine the
     * source and skip the leave-type check entirely.
     */
    public function test_a_partial_day_wins_over_a_source_bearing_event_name(): void
    {
        $plan = $this->mapper->map($this->companyA, $this->approval(
            eventType: 'leave_approved',
            leaveType: 'half-day',
        ));

        $this->assertEquals(HrmsEventPlan::IGNORED, $plan->resolution);
        $this->assertStringContainsString('partial_day_not_supported', (string) $plan->note);
    }

    public function test_a_company_can_add_its_own_partial_day_vocabulary(): void
    {
        config()->set("hrms.companies.{$this->companyA->id}.partial_day_types", ['Permission Hours']);

        $plan = $this->mapper->map($this->companyA, $this->approval(leaveType: 'permission hours'));

        $this->assertEquals(HrmsEventPlan::IGNORED, $plan->resolution);

        // The defaults still apply alongside it.
        $this->assertEquals(
            HrmsEventPlan::IGNORED,
            $this->mapper->map($this->companyA, $this->approval(leaveType: 'half-day'))->resolution,
        );
    }

    /**
     * A full-day type must still map, or the partial-day guard would be swallowing
     * ordinary leave.
     */
    public function test_a_full_day_leave_type_is_unaffected(): void
    {
        $plan = $this->mapper->map($this->companyA, $this->approval(leaveType: 'leave'));

        $this->assertEquals(HrmsEventPlan::APPLY, $plan->resolution);
        $this->assertEquals('leave', $plan->source);
        $this->assertNotEmpty($plan->createDates);
    }

    /**
     * Pins what (a) asked for: the event name alone decides the source, with no
     * leave type in the payload at all.
     */
    public function test_the_event_name_alone_determines_the_source(): void
    {
        $leave = $this->mapper->map($this->companyA, $this->approval(eventType: 'leave_approved'));
        $this->assertEquals(HrmsEventPlan::APPLY, $leave->resolution);
        $this->assertEquals('leave', $leave->source);

        $wfh = $this->mapper->map($this->companyA, $this->approval(eventType: 'wfh_approved'));
        $this->assertEquals(HrmsEventPlan::APPLY, $wfh->resolution);
        $this->assertEquals('wfh', $wfh->source);
    }

    public function test_leave_type_from_payload_is_used_when_the_event_name_is_generic(): void
    {
        config()->set("hrms.companies.{$this->companyA->id}.event_type_map", [
            'leave.changed' => ['action' => 'approved', 'source' => null],
        ]);
        config()->set("hrms.companies.{$this->companyA->id}.type_map", [
            'Work From Home' => 'wfh',
        ]);

        $plan = $this->mapper->map($this->companyA, $this->approval(
            eventType: 'leave.changed', leaveType: 'Work From Home'
        ));

        $this->assertTrue($plan->shouldApply());
        $this->assertEquals('wfh', $plan->source);
    }

    public function test_cancellation_lists_only_skips_carrying_this_leaves_reference(): void
    {
        $mine = $this->seedSkip('2026-10-06', 'L-1');
        $otherLeave = $this->seedSkip('2026-10-07', 'L-2');
        $handEntered = $this->seedSkip('2026-10-08', null);

        $plan = $this->mapper->map($this->companyA, $this->cancellation('L-1'));

        $this->assertTrue($plan->isCancellation());
        $this->assertEquals([$mine->id], $plan->releaseSkips()->pluck('id')->all());
        $this->assertNotContains($otherLeave->id, $plan->releaseSkips()->pluck('id')->all());
        $this->assertNotContains($handEntered->id, $plan->releaseSkips()->pluck('id')->all());

        // Still a read-only step: nothing was cancelled.
        $this->assertNull($mine->fresh()->cancelled_at);
    }

    public function test_cancellation_ignores_already_cancelled_skips(): void
    {
        $skip = $this->seedSkip('2026-10-06', 'L-1');
        $skip->update(['cancelled_at' => now(), 'cancelled_by' => $this->primaryAdminA->id]);

        $plan = $this->mapper->map($this->companyA, $this->cancellation('L-1'));

        $this->assertTrue($plan->releaseSkips()->isEmpty());
    }

    public function test_shortened_leave_lists_the_days_it_no_longer_covers(): void
    {
        $keep = $this->seedSkip('2026-10-05', 'L-1');
        $dropA = $this->seedSkip('2026-10-06', 'L-1');
        $dropB = $this->seedSkip('2026-10-07', 'L-1');

        // Re-sent as Mon 05 only.
        $plan = $this->mapper->map($this->companyA, $this->approval(from: '2026-10-05', to: '2026-10-05'));

        $this->assertEquals(['2026-10-05'], $plan->createDates);
        $this->assertEqualsCanonicalizing(
            [$dropA->id, $dropB->id],
            $plan->releaseSkips()->pluck('id')->all()
        );
        $this->assertNotContains($keep->id, $plan->releaseSkips()->pluck('id')->all());
    }

    public function test_cancel_actor_is_the_companys_primary_admin(): void
    {
        $plan = $this->mapper->map($this->companyA, $this->cancellation('L-1'));

        // The designated primary, not merely the lowest-id admin.
        $this->assertEquals($this->otherAdminA->id, $plan->actor->id);
        $this->assertNotEquals($this->primaryAdminA->id, $plan->actor->id);
    }

    public function test_primary_admin_belonging_to_another_company_is_refused(): void
    {
        $outsider = User::create([
            'name' => 'Outsider', 'email' => 'outsider@b.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyB->id,
        ]);

        CompanySetting::where('company_id', $this->companyA->id)
            ->update(['primary_admin_id' => $outsider->id]);
        $this->companyA->unsetRelation('setting');

        $plan = $this->mapper->map($this->companyA, $this->cancellation('L-1'));

        // Falls back inside company A rather than attributing to another tenant.
        $this->assertNotEquals($outsider->id, $plan->actor->id);
        $this->assertEquals($this->companyA->id, $plan->actor->company_id);
    }

    public function test_cancel_actor_falls_back_to_a_company_admin_of_the_same_company(): void
    {
        CompanySetting::where('company_id', $this->companyA->id)->update(['primary_admin_id' => null]);
        $this->companyA->unsetRelation('setting');

        $plan = $this->mapper->map($this->companyA, $this->cancellation('L-1'));

        // With no designated primary it takes the first admin by id, which is a
        // different user from the one designated above.
        $this->assertEquals($this->primaryAdminA->id, $plan->actor->id);
        $this->assertEquals($this->companyA->id, $plan->actor->company_id);
        $this->assertEquals('company_admin', $plan->actor->role);
    }

    public function test_cancel_actor_is_null_when_the_company_has_no_admin(): void
    {
        $plan = $this->mapper->map($this->companyB, $this->cancellation('L-1'));

        $this->assertNull($plan->actor);
    }

    public function test_approval_without_a_leave_id_is_unmappable(): void
    {
        $payload = $this->approval();
        unset($payload['leave']['id']);

        $this->assertEquals(HrmsEventPlan::UNMAPPABLE, $this->mapper->map($this->companyA, $payload)->resolution);
    }

    public function test_reversed_date_range_is_unmappable(): void
    {
        $payload = $this->approval(from: '2026-10-08', to: '2026-10-06');

        $this->assertEquals(HrmsEventPlan::UNMAPPABLE, $this->mapper->map($this->companyA, $payload)->resolution);
    }
}
