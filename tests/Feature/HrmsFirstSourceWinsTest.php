<?php

namespace Tests\Feature;

use App\Actions\Meal\CancelSkip;
use App\Actions\Meal\RecordSkip;
use App\Actions\Skip\ApplyHrmsLeaveEvent;
use App\Enums\SkipOutcome;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\HrmsWebhookEvent;
use App\Models\RecurringSkip;
use App\Models\Skip;
use App\Models\User;
use App\Services\Hrms\HrmsActorResolver;
use App\Services\Hrms\HrmsEventMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What happens when a day is already skipped and the HR system reports leave
 * for it too.
 *
 * skips has unique(employee_id, date), so there is only ever one row per person
 * per day - the two sources cannot coexist. First source wins: the existing row
 * and its source are kept, the HRMS event records the day as already skipped,
 * and - the part that matters - cancelling the HRMS leave afterwards must not
 * remove a row the HRMS did not create.
 */
class HrmsFirstSourceWinsTest extends TestCase
{
    use RefreshDatabase;

    protected const DATE = '2026-10-08';

    protected const LEAVE_REF = 'cp:leave:abc123';

    protected Company $company;

    protected User $admin;

    protected Employee $employee;

    protected HrmsEventMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->admin = User::create([
            'name' => 'Alpha HR', 'email' => 'hr@alpha.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        $employeeUser = User::create([
            'name' => 'Alice', 'email' => 'alice@alpha.test', 'password' => bcrypt('password'),
            'role' => 'employee', 'company_id' => $this->company->id, 'login_code' => 'EMP101',
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'user_id' => $employeeUser->id,
            'employee_code' => 'EMP101', 'external_id' => 'HR-1', 'email' => 'alice@alpha.test',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->mapper = new HrmsEventMapper(new HrmsActorResolver);
    }

    /** @return array<string, mixed> */
    protected function approval(): array
    {
        return [
            'event_id' => self::LEAVE_REF.':approved',
            'event_type' => 'leave_approved',
            'occurred_at' => '2026-10-06T02:30:00Z',
            'leave' => [
                'id' => self::LEAVE_REF,
                'employee_id' => 'HR-1',
                'from_date' => self::DATE,
                'to_date' => self::DATE,
                'reason' => 'CyberPulse casual leave',
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function cancellation(): array
    {
        return [
            'event_id' => self::LEAVE_REF.':cancelled',
            'event_type' => 'leave_cancelled',
            'occurred_at' => '2026-10-06T06:00:00Z',
            'leave' => ['id' => self::LEAVE_REF],
        ];
    }

    /** @return array<string, mixed> */
    protected function apply(array $event): array
    {
        return app(ApplyHrmsLeaveEvent::class)
            ->execute($this->company, $this->mapper->map($this->company, $event));
    }

    protected function seedSkip(string $source, ?string $reason = null): Skip
    {
        return Skip::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => self::DATE,
            'source' => $source,
            'reason' => $reason,
            'created_by' => in_array($source, ['hr'], true) ? $this->admin->id : null,
        ]);
    }

    /** @return array<int, array<int, string>> */
    public static function existingSources(): array
    {
        return [
            ['recurring'],
            ['hr'],
            ['self'],
        ];
    }

    /**
     * The existing row and its source survive, and the event says the day was
     * already covered rather than claiming to have applied anything.
     */
    #[DataProvider('existingSources')]
    public function test_an_hrms_leave_does_not_replace_an_existing_skip(string $source): void
    {
        $existing = $this->seedSkip($source, 'Set up beforehand');

        $result = $this->apply($this->approval());

        $this->assertEquals(HrmsWebhookEvent::STATUS_APPLIED, $result['status']);
        $this->assertEquals([self::DATE], $result['already_days']);
        $this->assertEmpty($result['applied_days']);

        // One row, still the original one, untouched.
        $this->assertDatabaseCount('skips', 1);

        $fresh = $existing->fresh();
        $this->assertEquals($source, $fresh->source);
        $this->assertEquals('Set up beforehand', $fresh->reason);
        $this->assertNull($fresh->external_ref, 'the HRMS must not stamp its reference onto a row it did not create');
        $this->assertNull($fresh->cancelled_at);
    }

    /**
     * The guarantee that matters. The HRMS releases skips by external_ref, and
     * it never acquired one here - so cancelling the leave leaves the day
     * skipped, because the reason it is skipped was never the leave.
     */
    #[DataProvider('existingSources')]
    public function test_cancelling_the_hrms_leave_leaves_the_existing_skip_alone(string $source): void
    {
        $existing = $this->seedSkip($source, 'Set up beforehand');

        $this->apply($this->approval());
        $cancel = $this->apply($this->cancellation());

        // Nothing to release: the HRMS owns no skip on this day.
        $this->assertEmpty($cancel['released_days']);
        $this->assertEquals(HrmsWebhookEvent::STATUS_IGNORED, $cancel['status']);

        $fresh = $existing->fresh();
        $this->assertNull($fresh->cancelled_at, "a {$source} skip was released by the HRMS");
        $this->assertEquals($source, $fresh->source);
        $this->assertDatabaseCount('skips', 1);
    }

    /**
     * A recurring rule generates its skip the same way, so the end-to-end shape
     * is asserted through the generator rather than a hand-made row.
     */
    public function test_a_generated_recurring_skip_survives_the_whole_cycle(): void
    {
        RecurringSkip::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            // 2026-10-08 is a Thursday.
            'weekday' => 4,
            'starts_on' => '2026-10-05',
            'active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->artisan('mealbells:generate-recurring-skips')->assertSuccessful();

        $generated = Skip::where('employee_id', $this->employee->id)->where('date', self::DATE)->sole();
        $this->assertEquals('recurring', $generated->source);

        $this->apply($this->approval());
        $this->apply($this->cancellation());

        $fresh = $generated->fresh();
        $this->assertNull($fresh->cancelled_at);
        $this->assertEquals('recurring', $fresh->source);
        $this->assertNull($fresh->external_ref);
    }

    /**
     * The mirror image: when the HRMS did create the skip, cancelling does
     * release it - otherwise the test above would pass for the wrong reason.
     */
    public function test_a_skip_the_hrms_created_is_released_on_cancellation(): void
    {
        $this->apply($this->approval());

        $skip = Skip::where('employee_id', $this->employee->id)->sole();
        $this->assertEquals(self::LEAVE_REF, $skip->external_ref);
        $this->assertEquals('leave', $skip->source);

        $cancel = $this->apply($this->cancellation());

        $this->assertEquals([self::DATE], $cancel['released_days']);
        $this->assertNotNull($skip->fresh()->cancelled_at);
    }

    /**
     * A person cancelling first, then the leave arriving: an automated source
     * must not resurrect what someone deliberately cancelled.
     */
    public function test_an_hrms_leave_cannot_revive_a_skip_a_person_cancelled(): void
    {
        $existing = $this->seedSkip('hr', 'Set up beforehand');
        app(CancelSkip::class)->execute($this->company, $existing, $this->admin);

        $result = $this->apply($this->approval());

        $this->assertEquals(HrmsWebhookEvent::STATUS_BLOCKED, $result['status']);
        $this->assertEquals(
            [['date' => self::DATE, 'reason' => 'blocked_cancelled']],
            $result['blocked_days'],
        );
        $this->assertNotNull($existing->fresh()->cancelled_at);
    }

    /**
     * Only this one day is contested; the rest of the range still applies.
     */
    public function test_the_other_days_of_the_range_are_unaffected(): void
    {
        $this->seedSkip('self', 'Already off');

        $event = $this->approval();
        $event['leave']['to_date'] = '2026-10-09';

        $result = $this->apply($event);

        $this->assertEquals([self::DATE], $result['already_days']);
        $this->assertEquals(['2026-10-09'], $result['applied_days']);

        // The contested day keeps its own source; the new day is the HRMS's.
        $this->assertEquals('self', Skip::where('date', self::DATE)->sole()->source);
        $this->assertEquals(self::LEAVE_REF, Skip::where('date', '2026-10-09')->sole()->external_ref);
    }

    /**
     * And the engine's own outcome for this, asserted directly - the status the
     * rest of the behaviour is built on.
     */
    public function test_record_skip_reports_already_skipped(): void
    {
        $this->seedSkip('recurring');

        $result = app(RecordSkip::class)->execute(
            $this->company, $this->employee, self::DATE, 'leave', 'CyberPulse casual leave', null, self::LEAVE_REF,
        );

        $this->assertEquals(SkipOutcome::ALREADY_SKIPPED, $result->outcome);
        $this->assertEquals('recurring', $result->skip->source);
    }
}
