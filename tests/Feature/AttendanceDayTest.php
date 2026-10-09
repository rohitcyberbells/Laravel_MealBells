<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The store for what the HR system said about who turned up.
 *
 * Shadow mode's whole promise is that the count does not move, so this table
 * exists alongside `skips` rather than inside it. What it must get right is the
 * difference between "was away" and "we do not know" - because treating the
 * second as the first is what would cost somebody their lunch.
 */
class AttendanceDayTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'ACME001',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    protected function record(?bool $clockedIn, bool $wfh = false, string $date = '2026-10-09'): AttendanceDay
    {
        return AttendanceDay::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => $date,
            'clocked_in_by_cutoff' => $clockedIn,
            'is_wfh' => $wfh,
        ]);
    }

    public function test_it_records_an_answer_for_one_employee_on_one_day(): void
    {
        $day = $this->record(true);

        $this->assertTrue($day->fresh()->clocked_in_by_cutoff);
        $this->assertFalse($day->fresh()->is_wfh);
        $this->assertSame('cyberpulse', $day->fresh()->source);
    }

    /**
     * The distinction the whole design rests on.
     */
    public function test_unknown_is_not_absent(): void
    {
        $this->record(null);

        $this->assertSame(0, AttendanceDay::absent()->count(), 'an unknown answer was counted as an absence');
        $this->assertSame(1, AttendanceDay::unknown()->count());
    }

    public function test_absent_means_known_to_have_been_absent(): void
    {
        $this->record(false);

        $this->assertSame(1, AttendanceDay::absent()->count());
        $this->assertSame(0, AttendanceDay::unknown()->count());
    }

    /**
     * A second pull on the same day must update rather than add, which is what
     * makes a repeated run idempotent.
     */
    public function test_one_answer_per_employee_per_day_is_enforced(): void
    {
        $this->record(false);

        $this->expectException(QueryException::class);

        $this->record(true);
    }

    public function test_the_same_employee_can_have_a_row_for_each_day(): void
    {
        $this->record(true, date: '2026-10-08');
        $this->record(false, date: '2026-10-09');

        $this->assertSame(2, AttendanceDay::count());
    }

    /**
     * No column holds a clock-in time, and none should: the decision is stored,
     * the measurement is thrown away.
     */
    public function test_no_arrival_time_is_stored_anywhere(): void
    {
        $columns = Schema::getColumnListing('attendance_days');

        foreach ($columns as $column) {
            $this->assertStringNotContainsString('time', strtolower($column), "attendance_days.{$column} looks like a timestamp");
            $this->assertStringNotContainsString('selfie', strtolower($column));
            $this->assertStringNotContainsString('latitude', strtolower($column));
            $this->assertStringNotContainsString('longitude', strtolower($column));
            $this->assertStringNotContainsString('location', strtolower($column));
        }
    }

    // ------------------------------------------------------- the company switch

    public function test_the_company_setting_is_off_for_every_existing_company(): void
    {
        $this->assertFalse(
            CompanySetting::where('company_id', $this->company->id)->firstOrFail()->attendance_absence_enabled,
            'attendance would have started acting on companies that never asked for it',
        );
    }

    public function test_the_company_setting_can_be_turned_on(): void
    {
        $setting = CompanySetting::where('company_id', $this->company->id)->firstOrFail();

        $setting->update(['attendance_absence_enabled' => true]);

        $this->assertTrue($setting->fresh()->attendance_absence_enabled);
    }

    /**
     * Archiving a company takes its attendance rows with it, the way it does
     * for everything else - except that a company is soft-deleted, so in
     * practice nothing is destroyed at all.
     */
    public function test_rows_belong_to_their_company_and_employee(): void
    {
        $day = $this->record(true);

        $this->assertSame($this->company->id, $day->company->id);
        $this->assertSame($this->employee->id, $day->employee->id);
    }
}
