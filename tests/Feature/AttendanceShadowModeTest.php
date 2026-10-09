<?php

namespace Tests\Feature;

use App\Actions\Hrms\PullAttendance;
use App\Actions\Meal\CalculateExpectedMeals;
use App\Models\AttendanceDay;
use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealAdjustment;
use App\Models\MealCount;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Shadow mode's one promise: nothing the kitchen receives changes.
 *
 * The feature exists to produce a number - how many meals we would not have
 * ordered - so that deciding whether to act on attendance is a decision about
 * evidence rather than a guess. Every test here is either about that number
 * being right, or about the count being untouched while it is produced.
 */
class AttendanceShadowModeTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected TiffinService $tiffin;

    protected User $admin;

    /** @var array<string, Employee> */
    protected array $employees = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tiffin = TiffinService::create([
            'name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890',
        ]);

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            'attendance_absence_enabled' => true,
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $this->company->id, 'tiffin_service_id' => $this->tiffin->id,
            'is_active' => true, 'assigned_at' => '2026-09-01',
        ]);

        $this->admin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        CompanyHrmsConnection::create([
            'company_id' => $this->company->id,
            'pull_adapter' => 'cyberpulse',
            'pull_base_url' => 'https://hrms.example.test',
            'attendance_api_key' => 'secret-attendance-key',
        ]);

        // Ten employees whose attendance is read, and two whose is not.
        foreach (range(1, 10) as $i) {
            $this->employees['e'.$i] = Employee::create([
                'company_id' => $this->company->id,
                'employee_code' => 'ACME'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => "Person {$i}", 'status' => 'active', 'is_meal_eligible' => true,
                'attendance_source' => 'integrated', 'external_id' => 'hr-'.$i,
            ]);
        }

        $this->employees['onPaper'] = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'ACME090',
            'name' => 'On Paper', 'status' => 'active', 'is_meal_eligible' => true,
            'attendance_source' => 'manual', 'external_id' => 'hr-90',
        ]);

        $this->employees['noTracking'] = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'ACME091',
            'name' => 'No Tracking', 'status' => 'active', 'is_meal_eligible' => true,
            'attendance_source' => 'none', 'external_id' => 'hr-91',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function fakeVendor(array $rows, int $status = 200, string $date = '2026-10-09'): void
    {
        Http::fake([
            '*/api/integration/attendance/daily*' => Http::response([
                'date' => $date,
                'timezone' => 'Asia/Kolkata',
                'employees' => $rows,
            ], $status),
        ]);
    }

    /** @return array<string, mixed> */
    protected function row(string $hrId, ?bool $clockedIn, bool $wfh = false, ?string $at = null): array
    {
        return [
            'employee_id' => $hrId,
            'email' => null,
            'clocked_in' => $clockedIn !== false,
            // 04:15Z is 09:45 IST, comfortably before an 11:00 cutoff.
            'clock_in_at' => $clockedIn === true ? ($at ?? '2026-10-09T04:15:00Z') : ($clockedIn === null ? 'enc:unreadable' : null),
            'is_wfh' => $wfh,
        ];
    }

    protected function pull(bool $dryRun = false, string $date = '2026-10-09'): array
    {
        return app(PullAttendance::class)->execute($this->company, $date, $dryRun);
    }

    /**
     * Everything the kitchen's number is made of, reduced to one comparable
     * string.
     */
    protected function kitchenFigures(string $date = '2026-10-09'): string
    {
        $calculated = (new CalculateExpectedMeals)->execute($this->company, $date);

        return json_encode([
            'calculated' => $calculated,
            'skips' => Skip::where('company_id', $this->company->id)
                ->orderBy('id')->get(['employee_id', 'date', 'source', 'cancelled_at'])->toArray(),
            'counts' => MealCount::where('company_id', $this->company->id)
                ->orderBy('id')->get(['date', 'base_eligible_count', 'skip_count', 'final_expected_count', 'locked_at'])->toArray(),
            'extras' => MealAdjustment::where('company_id', $this->company->id)
                ->orderBy('id')->get(['date', 'quantity', 'cancelled_at'])->toArray(),
        ]);
    }

    // ============================================= the promise: nothing changes

    /**
     * The test this whole feature has to pass.
     */
    public function test_a_pull_changes_no_skip_no_count_and_no_kitchen_figure(): void
    {
        // Give the day some real content first: a leave, an extra, a locked
        // snapshot - so "nothing changed" is a claim about something.
        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employees['e1']->id,
            'date' => '2026-10-09', 'source' => 'leave', 'created_by' => $this->admin->id,
        ]);

        MealAdjustment::create([
            'company_id' => $this->company->id, 'date' => '2026-10-09',
            'quantity' => 4, 'type' => 'guest', 'created_by' => $this->admin->id,
        ]);

        MealCount::create([
            'company_id' => $this->company->id, 'tiffin_service_id' => $this->tiffin->id,
            'date' => '2026-10-08', 'base_eligible_count' => 12, 'skip_count' => 0, 'extra_count' => 0,
            'final_expected_count' => 12, 'breakdown' => [], 'status' => 'confirmed', 'locked_at' => now(),
        ]);

        $before = $this->kitchenFigures();

        // Six of the ten did not clock in - a lot, but under the guard.
        $this->fakeVendor([
            $this->row('hr-1', true), $this->row('hr-2', true), $this->row('hr-3', true),
            $this->row('hr-4', true),
            $this->row('hr-5', false), $this->row('hr-6', false), $this->row('hr-7', false),
            $this->row('hr-8', false),
            $this->row('hr-9', true), $this->row('hr-10', true),
        ]);

        $summary = $this->pull();

        $this->assertTrue($summary['ok']);
        $this->assertSame(4, $summary['absent']);

        $this->assertSame($before, $this->kitchenFigures(), 'shadow mode moved a kitchen figure');
    }

    public function test_it_creates_no_skip_whatever_the_attendance_says(): void
    {
        $this->fakeVendor(array_map(fn (int $i) => $this->row('hr-'.$i, false), range(1, 4)));

        $this->pull();

        $this->assertSame(0, Skip::where('company_id', $this->company->id)->count());
    }

    public function test_the_only_thing_it_writes_is_attendance_days(): void
    {
        $this->fakeVendor([$this->row('hr-1', true), $this->row('hr-2', false)]);

        $this->pull();

        $this->assertSame(2, AttendanceDay::where('company_id', $this->company->id)->count());
        $this->assertSame(0, Skip::count());
        $this->assertSame(0, MealCount::count());
        $this->assertSame(0, MealAdjustment::count());
    }

    // ===================================================== the number it produces

    /**
     * Somebody on approved leave is attributed to leave. Counting them here as
     * well would double the saving and overstate the case for acting.
     */
    public function test_would_be_absent_excludes_people_already_not_eating(): void
    {
        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employees['e1']->id,
            'date' => '2026-10-09', 'source' => 'leave', 'created_by' => $this->admin->id,
        ]);

        // e1 is on leave AND did not clock in; e2 and e3 just did not clock in.
        $this->fakeVendor([
            $this->row('hr-1', false), $this->row('hr-2', false), $this->row('hr-3', false),
            $this->row('hr-4', true),
        ]);

        $summary = $this->pull();

        $this->assertSame(3, $summary['absent'], 'three were absent');
        $this->assertSame(2, $summary['would_be_absent'], 'the one on leave was counted twice');
        $this->assertSame(1, $summary['already_not_eating']);
    }

    public function test_an_unknown_answer_is_never_a_would_be_absence(): void
    {
        $this->fakeVendor([$this->row('hr-1', null), $this->row('hr-2', null), $this->row('hr-3', true)]);

        $summary = $this->pull();

        // Two unreadable, plus the seven the response never mentioned: one
        // bucket, because both mean the same thing - we do not know - and
        // neither may become an absence.
        $this->assertSame(9, $summary['unknown']);
        $this->assertSame(0, $summary['absent']);
        $this->assertSame(0, $summary['would_be_absent'], 'an unreadable answer cost somebody a meal');
    }

    /**
     * Employees the HR system said nothing about at all. Silence is not
     * evidence of absence.
     */
    public function test_employees_missing_from_the_response_are_unknown_not_absent(): void
    {
        // Only two of the ten integrated employees appear.
        $this->fakeVendor([$this->row('hr-1', true), $this->row('hr-2', false)]);

        $summary = $this->pull();

        $this->assertSame(10, $summary['eligible']);
        $this->assertSame(1, $summary['absent']);
        $this->assertSame(8, $summary['unknown'], 'employees absent from the response were treated as away');
        $this->assertSame(1, $summary['would_be_absent']);
    }

    // =========================================================== attendance_source

    /**
     * The column has existed all along and meant nothing. This gives it one.
     */
    public function test_only_integrated_employees_are_read(): void
    {
        $this->fakeVendor([
            $this->row('hr-1', false),
            $this->row('hr-90', false),   // attendance kept on paper
            $this->row('hr-91', false),   // not tracked at all
        ]);

        $summary = $this->pull();

        $this->assertSame(10, $summary['eligible'], 'a non-integrated employee was counted as eligible');
        $this->assertSame(1, $summary['absent']);
        $this->assertSame(1, $summary['would_be_absent']);

        $this->assertFalse(
            AttendanceDay::where('employee_id', $this->employees['onPaper']->id)->exists(),
            'attendance was recorded for an employee whose attendance is kept on paper',
        );
        $this->assertFalse(
            AttendanceDay::where('employee_id', $this->employees['noTracking']->id)->exists(),
        );
    }

    // ================================================================ the guards

    /**
     * Forty percent of a company is not away; the HR system is more likely
     * broken, mis-scoped or mid-restart.
     */
    public function test_too_many_absences_records_nothing_and_is_marked_suspicious(): void
    {
        // Nine of ten absent.
        $this->fakeVendor(array_merge(
            array_map(fn (int $i) => $this->row('hr-'.$i, false), range(1, 9)),
            [$this->row('hr-10', true)],
        ));

        $summary = $this->pull();

        $this->assertSame('suspicious', $summary['status']);
        $this->assertSame(0, $summary['would_be_absent']);
        $this->assertNotEmpty($summary['warnings']);
        $this->assertSame(0, AttendanceDay::count(), 'a suspicious day was recorded anyway');
    }

    public function test_a_share_just_under_the_limit_is_recorded(): void
    {
        // Four of ten is 40%, which is not over the limit.
        $this->fakeVendor(array_merge(
            array_map(fn (int $i) => $this->row('hr-'.$i, false), range(1, 4)),
            array_map(fn (int $i) => $this->row('hr-'.$i, true), range(5, 10)),
        ));

        $summary = $this->pull();

        $this->assertSame('ok', $summary['status']);
        $this->assertSame(4, $summary['would_be_absent']);
        $this->assertSame(10, AttendanceDay::count());
    }

    public function test_the_share_limit_is_configurable(): void
    {
        config()->set('hrms.cyberpulse.max_absent_share', 0.1);

        $this->fakeVendor(array_merge(
            [$this->row('hr-1', false), $this->row('hr-2', false)],
            array_map(fn (int $i) => $this->row('hr-'.$i, true), range(3, 10)),
        ));

        $this->assertSame('suspicious', $this->pull()['status']);
    }

    /**
     * A failed fetch looks exactly like everybody being absent.
     */
    public function test_a_failed_fetch_records_nothing(): void
    {
        $this->fakeVendor([], 500);

        $summary = $this->pull();

        $this->assertSame('error', $summary['status']);
        $this->assertFalse($summary['ok']);
        $this->assertSame(0, AttendanceDay::count());
        $this->assertSame(0, $summary['would_be_absent']);
    }

    public function test_a_transport_failure_records_nothing(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $summary = $this->pull();

        $this->assertSame('error', $summary['status']);
        $this->assertSame(0, AttendanceDay::count());
    }

    /**
     * An empty response is the HR system telling us nothing, which is the same
     * as a failed fetch - not a day when nobody came in.
     */
    public function test_an_empty_response_records_nothing_and_is_suspicious(): void
    {
        $this->fakeVendor([]);

        $summary = $this->pull();

        $this->assertSame('suspicious', $summary['status']);
        $this->assertSame(0, AttendanceDay::count());
        $this->assertSame(0, $summary['would_be_absent']);
    }

    // =========================================================== when it runs

    public function test_a_company_without_the_setting_is_skipped(): void
    {
        CompanySetting::where('company_id', $this->company->id)
            ->update(['attendance_absence_enabled' => false]);

        $this->fakeVendor([$this->row('hr-1', false)]);

        $summary = $this->pull();

        $this->assertSame('skipped', $summary['status']);
        Http::assertNothingSent();
    }

    public function test_a_company_without_a_key_is_skipped(): void
    {
        CompanyHrmsConnection::where('company_id', $this->company->id)
            ->first()->forceFill(['attendance_api_key' => null])->save();

        $summary = $this->pull();

        $this->assertSame('skipped', $summary['status']);
        $this->assertStringContainsString('API key', (string) $summary['reason']);
    }

    public function test_a_non_meal_day_is_skipped_without_asking_the_vendor(): void
    {
        $this->fakeVendor([$this->row('hr-1', false)]);

        // 2026-10-10 is a Saturday.
        $summary = $this->pull(date: '2026-10-10');

        $this->assertSame('skipped', $summary['status']);
        Http::assertNothingSent();
    }

    // ================================================================ idempotency

    public function test_running_twice_records_the_same_rows(): void
    {
        $this->fakeVendor([$this->row('hr-1', true), $this->row('hr-2', false)]);

        $this->pull();
        $first = AttendanceDay::orderBy('employee_id')->get(['employee_id', 'clocked_in_by_cutoff'])->toJson();

        $this->pull();
        $second = AttendanceDay::orderBy('employee_id')->get(['employee_id', 'clocked_in_by_cutoff'])->toJson();

        $this->assertSame(2, AttendanceDay::count(), 'a second run added rows instead of updating them');
        $this->assertSame($first, $second);
    }

    public function test_a_later_run_can_correct_an_earlier_answer(): void
    {
        // A sequence rather than two fake() calls: a second fake() adds a stub
        // behind the first for the same pattern, so the original response would
        // keep being served and the test would pass for the wrong reason.
        Http::fake([
            '*/api/integration/attendance/daily*' => Http::sequence()
                ->push(['date' => '2026-10-09', 'employees' => [$this->row('hr-1', false)]])
                ->push(['date' => '2026-10-09', 'employees' => [$this->row('hr-1', true)]]),
        ]);

        $this->pull();
        $this->assertFalse(AttendanceDay::firstOrFail()->clocked_in_by_cutoff);

        // They clocked in late, still before the cutoff, and the next read sees
        // it. In Phase 2 this is the window in which a skip could be released;
        // after the cutoff it never could.
        $this->pull();

        $this->assertTrue(AttendanceDay::firstOrFail()->clocked_in_by_cutoff);
        $this->assertSame(1, AttendanceDay::count());
    }

    public function test_a_dry_run_records_nothing_but_still_reports(): void
    {
        $this->fakeVendor([$this->row('hr-1', false), $this->row('hr-2', true)]);

        $summary = $this->pull(dryRun: true);

        $this->assertTrue($summary['ok']);
        $this->assertSame(1, $summary['would_be_absent']);
        $this->assertSame(0, AttendanceDay::count(), 'a dry run wrote to the database');

        $connection = CompanyHrmsConnection::where('company_id', $this->company->id)->firstOrFail();
        $this->assertNull($connection->last_attendance_pull_at, 'a dry run stamped the connection');
    }

    // ============================================================== unmatched

    /**
     * Treated as present, so no meal is lost - but visible, because a feature
     * running on an unknown fraction of the workforce is worse than one
     * switched off.
     */
    public function test_an_unmatched_row_is_reported_and_costs_nobody_a_meal(): void
    {
        $this->fakeVendor([
            ['employee_id' => 'hr-stranger', 'email' => 'stranger@elsewhere.test', 'clocked_in' => false, 'clock_in_at' => null, 'is_wfh' => false],
            $this->row('hr-1', false),
        ]);

        $summary = $this->pull();

        $this->assertSame(1, $summary['unmatched']);
        $this->assertContains('hr-stranger', $summary['unmatched_references']);
        $this->assertSame(1, $summary['absent'], 'the stranger was counted as one of ours');
        $this->assertSame(1, $summary['would_be_absent']);
    }

    public function test_the_summary_names_no_employee(): void
    {
        $this->fakeVendor([
            ['employee_id' => 'hr-stranger', 'email' => 'Private Person <private@elsewhere.test>', 'clocked_in' => false, 'clock_in_at' => null, 'is_wfh' => false],
            $this->row('hr-1', true),
        ]);

        $summary = $this->pull();
        $json = json_encode($summary);

        $this->assertStringNotContainsString('Person 1', $json);
        $this->assertStringNotContainsString('ACME001', $json);
    }

    // ================================================================= the record

    public function test_the_run_is_recorded_on_the_connection(): void
    {
        $this->fakeVendor([$this->row('hr-1', false), $this->row('hr-2', true)]);

        $this->pull();

        $connection = CompanyHrmsConnection::where('company_id', $this->company->id)->firstOrFail();

        $this->assertNotNull($connection->last_attendance_pull_at);
        $this->assertSame('ok', $connection->last_attendance_pull_status);
        $this->assertSame(1, $connection->last_attendance_pull_summary['would_be_absent']);
    }

    public function test_the_work_from_home_flag_is_recorded(): void
    {
        $this->fakeVendor([$this->row('hr-1', true, wfh: true)]);

        $this->pull();

        $this->assertTrue(AttendanceDay::firstOrFail()->is_wfh);
    }
}
