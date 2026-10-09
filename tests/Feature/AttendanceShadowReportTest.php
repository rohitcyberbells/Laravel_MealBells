<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealAdjustment;
use App\Models\MealCount;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The report HR reads, and the number the decision turns on.
 *
 * Its job is to be honest in both directions: not to understate the saving by
 * averaging over days it never read, and not to overstate it by counting people
 * who were already not eating. A report that flatters the case for acting on
 * attendance is worse than no report, because it would be believed.
 */
class AttendanceShadowReportTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    /** @var array<int, Employee> */
    protected array $employees = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);

        $tiffin = TiffinService::create([
            'name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890',
        ]);

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            'attendance_absence_enabled' => true,
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $this->company->id, 'tiffin_service_id' => $tiffin->id,
            'is_active' => true, 'assigned_at' => '2026-09-01',
        ]);

        $this->admin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        foreach (range(1, 10) as $i) {
            $this->employees[$i] = Employee::create([
                'company_id' => $this->company->id,
                'employee_code' => 'ACME'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => "Person {$i}", 'status' => 'active', 'is_meal_eligible' => true,
                'attendance_source' => 'integrated',
            ]);
        }
    }

    protected function attendance(int $employee, ?bool $clockedIn, string $date): void
    {
        AttendanceDay::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employees[$employee]->id,
            'date' => $date,
            'clocked_in_by_cutoff' => $clockedIn,
        ]);
    }

    /** @return array<string, mixed> */
    protected function report(int $range = 14): array
    {
        $response = $this->actingAs(User::findOrFail($this->admin->id))
            ->get('/company-admin/reports/attendance?range='.$range);

        $response->assertStatus(200);

        return $response->getOriginalContent()->getData()['page']['props']['report'];
    }

    // 2026-10-05 is a Monday; the clock is frozen at 2026-10-05 08:00 IST, so
    // "today" is the Monday and the range looks backwards from it.

    public function test_an_unread_day_is_marked_unread_rather_than_zero(): void
    {
        $report = $this->report();

        $this->assertSame(0, $report['days_read']);
        $this->assertGreaterThan(0, $report['meal_days']);

        foreach ($report['days'] as $day) {
            $this->assertFalse($day['read'], "{$day['date']} was reported as read");
        }
    }

    /**
     * Averaging over days it never read would understate the saving and make
     * the feature look less useful than it is.
     */
    public function test_the_average_is_over_days_read_not_days_in_range(): void
    {
        // One day read, with three would-be absences.
        foreach ([1, 2, 3] as $i) {
            $this->attendance($i, false, '2026-10-05');
        }
        foreach ([4, 5, 6, 7, 8, 9, 10] as $i) {
            $this->attendance($i, true, '2026-10-05');
        }

        $report = $this->report();

        $this->assertSame(1, $report['days_read']);
        $this->assertSame(3, $report['total_would_be_absent']);
        $this->assertSame(3.0, $report['average_per_day'], 'the average was diluted by days never read');
    }

    public function test_the_average_is_null_when_nothing_has_been_read(): void
    {
        $this->assertNull($this->report()['average_per_day']);
    }

    /**
     * The other direction. Somebody on approved leave is counted once.
     */
    public function test_someone_already_not_eating_is_not_counted_as_a_saving(): void
    {
        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employees[1]->id,
            'date' => '2026-10-05', 'source' => 'leave', 'created_by' => $this->admin->id,
        ]);

        $this->attendance(1, false, '2026-10-05');
        $this->attendance(2, false, '2026-10-05');
        $this->attendance(3, true, '2026-10-05');

        $day = collect($this->report()['days'])->firstWhere('date', '2026-10-05');

        $this->assertSame(2, $day['absent']);
        $this->assertSame(1, $day['would_be_absent'], 'the person on leave was counted as a saving');
        $this->assertSame(1, $day['already_not_eating']);
    }

    public function test_an_unknown_answer_is_reported_but_never_a_saving(): void
    {
        $this->attendance(1, null, '2026-10-05');
        $this->attendance(2, null, '2026-10-05');
        $this->attendance(3, true, '2026-10-05');

        $day = collect($this->report()['days'])->firstWhere('date', '2026-10-05');

        $this->assertSame(2, $day['unknown']);
        $this->assertSame(0, $day['absent']);
        $this->assertSame(0, $day['would_be_absent']);
    }

    public function test_non_meal_days_are_left_out_entirely(): void
    {
        $report = $this->report(range: 7);

        $dates = array_column($report['days'], 'date');

        // 2026-10-03 and 2026-10-04 are a Saturday and Sunday.
        $this->assertNotContains('2026-10-03', $dates);
        $this->assertNotContains('2026-10-04', $dates);
        $this->assertContains('2026-10-05', $dates);
    }

    /**
     * A feature running on an unknown fraction of the workforce is worse than
     * one switched off, so the screen has to show the fraction.
     */
    public function test_it_reports_how_many_employees_are_actually_read(): void
    {
        Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'ACME090',
            'name' => 'On Paper', 'status' => 'active', 'is_meal_eligible' => true,
            'attendance_source' => 'manual',
        ]);

        $report = $this->report();

        $this->assertSame(11, $report['eligible_employees']);
        $this->assertSame(10, $report['employees_read']);
    }

    public function test_it_reports_whether_the_feature_is_switched_on(): void
    {
        $this->assertTrue($this->report()['enabled']);

        CompanySetting::where('company_id', $this->company->id)
            ->update(['attendance_absence_enabled' => false]);

        $this->assertFalse($this->report()['enabled']);
    }

    // ------------------------------------------------------- it changes nothing

    /**
     * It is a report. Opening it must not write anything.
     */
    public function test_opening_the_report_writes_nothing(): void
    {
        $this->attendance(1, false, '2026-10-05');

        $before = json_encode([
            'skips' => Skip::count(),
            'counts' => MealCount::count(),
            'extras' => MealAdjustment::count(),
            'attendance' => AttendanceDay::get(['employee_id', 'clocked_in_by_cutoff'])->toJson(),
        ]);

        $this->report();

        $this->assertSame($before, json_encode([
            'skips' => Skip::count(),
            'counts' => MealCount::count(),
            'extras' => MealAdjustment::count(),
            'attendance' => AttendanceDay::get(['employee_id', 'clocked_in_by_cutoff'])->toJson(),
        ]));
    }

    // ----------------------------------------------------------- who may read it

    public function test_another_tenants_attendance_is_not_visible(): void
    {
        $other = Company::create(['name' => 'Beta Corp', 'code' => 'BETA01']);

        CompanySetting::create([
            'company_id' => $other->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            'attendance_absence_enabled' => true,
        ]);

        $theirEmployee = Employee::create([
            'company_id' => $other->id, 'employee_code' => 'BETA001',
            'name' => 'Carol', 'status' => 'active', 'is_meal_eligible' => true,
            'attendance_source' => 'integrated',
        ]);

        AttendanceDay::create([
            'company_id' => $other->id, 'employee_id' => $theirEmployee->id,
            'date' => '2026-10-05', 'clocked_in_by_cutoff' => false,
        ]);

        $report = $this->report();

        $this->assertSame(0, $report['total_would_be_absent'], "another tenant's absence appeared in this report");
        $this->assertSame(0, $report['days_read']);
    }

    public function test_an_employee_cannot_open_it(): void
    {
        $employeeUser = User::create([
            'name' => 'Alice', 'email' => 'alice@acme.test', 'password' => bcrypt('password'),
            'role' => 'employee', 'company_id' => $this->company->id,
        ]);

        $this->actingAs($employeeUser)->get('/company-admin/reports/attendance')->assertStatus(403);
    }

    public function test_a_guest_cannot_open_it(): void
    {
        $this->get('/company-admin/reports/attendance')->assertRedirect('/login');
    }

    // ---------------------------------------------------------------- the screen

    /**
     * Said first and said plainly: a page full of absences is otherwise easy to
     * read as meals already withheld.
     */
    public function test_the_screen_says_nothing_was_changed(): void
    {
        $page = file_get_contents(resource_path('js/Pages/CompanyAdmin/Reports/Attendance.vue'));

        $this->assertStringContainsString('Nothing here changed a single meal', $page);
        $this->assertStringContainsString('No skip was created', $page);
    }

    public function test_the_report_has_a_menu_entry(): void
    {
        $props = $this->actingAs(User::findOrFail($this->admin->id))
            ->get('/company-admin/dashboard')
            ->getOriginalContent()->getData()['page']['props'];

        $this->assertContains(
            '/company-admin/reports/attendance',
            collect($props['navigation'])->pluck('href')->all(),
        );
    }
}
