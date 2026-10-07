<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyCalendarDay;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\MealCount;
use App\Models\TiffinService;
use App\Models\User;
use App\Services\MealCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /company-admin/calendar/bulk - declaring many dates at once.
 *
 * This endpoint had no coverage at all, which is why it is worth testing before
 * it gets a UI: there was no proof it worked.
 *
 * The clock is frozen at Monday 2026-10-05 08:00 IST by TestCase.
 */
class CompanyCalendarBulkTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected Employee $employee;

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

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'EMP101',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    protected function bulk(array $payload = [])
    {
        return $this->actingAs($this->admin)->from('/company-admin/calendar')
            ->post('/company-admin/calendar/bulk', array_merge([
                'dates' => ['2026-10-07', '2026-10-08', '2026-10-09'],
                'type' => 'holiday',
                'note' => 'Diwali week',
            ], $payload));
    }

    // ------------------------------------------------------------- the happy path

    public function test_many_dates_are_declared_holidays_in_one_call(): void
    {
        $this->bulk()->assertRedirect('/company-admin/calendar')->assertSessionHasNoErrors();

        $this->assertEquals(
            ['2026-10-07', '2026-10-08', '2026-10-09'],
            CompanyCalendarDay::where('company_id', $this->company->id)->orderBy('date')
                ->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all(),
        );

        foreach (CompanyCalendarDay::all() as $day) {
            $this->assertEquals('holiday', $day->type);
            $this->assertEquals('Diwali week', $day->note);
            $this->assertEquals($this->admin->id, $day->created_by);
        }
    }

    public function test_the_message_says_how_many_were_updated(): void
    {
        $this->bulk()->assertSessionHas('message', 'Updated 3 calendar days successfully.');
    }

    public function test_the_message_is_singular_for_one_day(): void
    {
        $this->bulk(['dates' => ['2026-10-08']])
            ->assertSessionHas('message', 'Updated 1 calendar day successfully.');
    }

    /**
     * The whole point of declaring a holiday: those days stop counting.
     */
    public function test_a_declared_holiday_stops_being_a_meal_day(): void
    {
        $this->assertTrue(MealCalendar::isMealDay($this->company, '2026-10-08'));

        $this->bulk(['dates' => ['2026-10-08']]);

        $this->assertFalse(MealCalendar::isMealDay($this->company->fresh(), '2026-10-08'));
    }

    /**
     * And the reverse: a working_day override makes a weekend count.
     */
    public function test_a_working_day_override_turns_a_weekend_into_a_meal_day(): void
    {
        // 2026-10-10 is a Saturday, 2026-10-11 a Sunday - neither is in meal_days.
        $this->assertFalse(MealCalendar::isMealDay($this->company, '2026-10-10'));

        $this->bulk(['dates' => ['2026-10-10', '2026-10-11'], 'type' => 'working_day', 'note' => 'Stock take']);

        $this->assertTrue(MealCalendar::isMealDay($this->company->fresh(), '2026-10-10'));
        $this->assertTrue(MealCalendar::isMealDay($this->company->fresh(), '2026-10-11'));
    }

    public function test_re_declaring_a_date_updates_it_rather_than_duplicating(): void
    {
        $this->bulk(['dates' => ['2026-10-08'], 'type' => 'holiday', 'note' => 'First call']);
        $this->bulk(['dates' => ['2026-10-08'], 'type' => 'working_day', 'note' => 'Second call']);

        $day = CompanyCalendarDay::where('company_id', $this->company->id)->sole();
        $this->assertEquals('working_day', $day->type);
        $this->assertEquals('Second call', $day->note);
    }

    public function test_clearing_a_day_restores_the_weekly_default(): void
    {
        $this->bulk(['dates' => ['2026-10-08']]);
        $day = CompanyCalendarDay::sole();
        $this->assertFalse(MealCalendar::isMealDay($this->company->fresh(), '2026-10-08'));

        $this->actingAs($this->admin)->from('/company-admin/calendar')
            ->delete("/company-admin/calendar/{$day->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('company_calendar_days', 0);
        $this->assertTrue(MealCalendar::isMealDay($this->company->fresh(), '2026-10-08'));
    }

    // ----------------------------------------------------------------- refusals

    /**
     * A past day cannot be changed; the days that can be are still applied.
     */
    public function test_past_dates_are_passed_over_and_the_rest_applied(): void
    {
        $this->bulk(['dates' => ['2026-10-01', '2026-10-08']])
            ->assertSessionHas('message', 'Updated 1 calendar day successfully.');

        $this->assertEquals(
            ['2026-10-08'],
            CompanyCalendarDay::pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all(),
        );
    }

    /**
     * The count has already been sent to the kitchen, so the calendar for that
     * day is settled.
     */
    public function test_a_locked_date_is_passed_over(): void
    {
        $tiffin = TiffinService::create(['name' => 'Tiffin Co', 'address' => 'Addr', 'contact_phone' => '9876543210']);

        MealCount::create([
            'company_id' => $this->company->id,
            'tiffin_service_id' => $tiffin->id,
            'date' => '2026-10-08',
            'base_eligible_count' => 1, 'skip_count' => 0, 'extra_count' => 0,
            'final_expected_count' => 1, 'adjusted_total' => 1,
            'breakdown' => [], 'status' => 'confirmed', 'locked_at' => now(),
        ]);

        $this->bulk(['dates' => ['2026-10-08', '2026-10-09']])
            ->assertSessionHas('message', 'Updated 1 calendar day successfully.');

        $this->assertDatabaseMissing('company_calendar_days', ['date' => '2026-10-08']);
        $this->assertDatabaseHas('company_calendar_days', ['date' => '2026-10-09']);
    }

    public function test_an_unknown_type_is_rejected_outright(): void
    {
        $this->bulk(['type' => 'half_day'])->assertSessionHasErrors('type');

        $this->assertDatabaseCount('company_calendar_days', 0);
    }

    public function test_a_malformed_date_is_rejected_outright(): void
    {
        $this->bulk(['dates' => ['08-10-2026']])->assertSessionHasErrors('dates.0');

        $this->assertDatabaseCount('company_calendar_days', 0);
    }

    public function test_an_empty_list_is_rejected(): void
    {
        $this->bulk(['dates' => []])->assertSessionHasErrors('dates');
    }

    public function test_a_note_longer_than_the_column_is_rejected(): void
    {
        $this->bulk(['note' => str_repeat('x', 256)])->assertSessionHasErrors('note');

        $this->assertDatabaseCount('company_calendar_days', 0);
    }

    // ------------------------------------------------------------------- tenancy

    public function test_another_companys_admin_cannot_declare_our_days(): void
    {
        $other = Company::create(['name' => 'Beta Corp', 'code' => 'BETA1']);
        CompanySetting::create([
            'company_id' => $other->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);
        $otherAdmin = User::create([
            'name' => 'Beta HR', 'email' => 'hr@beta.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $other->id,
        ]);

        $this->actingAs($otherAdmin)->post('/company-admin/calendar/bulk', [
            'dates' => ['2026-10-08'], 'type' => 'holiday',
        ]);

        // Their own calendar, never ours.
        $this->assertDatabaseMissing('company_calendar_days', ['company_id' => $this->company->id]);
        $this->assertDatabaseHas('company_calendar_days', ['company_id' => $other->id]);
    }

    public function test_an_employee_cannot_reach_it(): void
    {
        $employeeUser = User::create([
            'name' => 'Alice', 'email' => 'alice@alpha.test', 'password' => bcrypt('password'),
            'role' => 'employee', 'company_id' => $this->company->id, 'login_code' => 'EMP101',
        ]);

        $this->actingAs($employeeUser)
            ->post('/company-admin/calendar/bulk', ['dates' => ['2026-10-08'], 'type' => 'holiday'])
            ->assertStatus(403);

        $this->assertDatabaseCount('company_calendar_days', 0);
    }

    public function test_a_guest_cannot_reach_it(): void
    {
        $this->post('/company-admin/calendar/bulk', ['dates' => ['2026-10-08'], 'type' => 'holiday'])
            ->assertRedirect('/login');

        $this->assertDatabaseCount('company_calendar_days', 0);
    }

    // --------------------------------------------------------------- honesty

    /**
     * Refused dates are passed over rather than failing the call, so a request
     * can come back having done nothing - and a green "Updated 0 calendar days
     * successfully" reads as if it worked.
     */
    public function test_a_call_where_every_date_is_refused_says_so(): void
    {
        $this->bulk(['dates' => ['2026-09-01', '2026-09-02']])
            ->assertSessionHasErrors('calendar');

        $this->assertDatabaseCount('company_calendar_days', 0);
    }
}
