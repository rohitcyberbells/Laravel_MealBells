<?php

namespace Tests\Feature;

use App\Actions\Calendar\SetCalendarDay;
use App\Actions\Meal\BuildVendorPreparationView;
use App\Actions\Meal\RecordSkip;
use App\Actions\Recurring\CreateRecurringSkip;
use App\Actions\Recurring\PauseRecurringSkip;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\CompanyCalendarDay;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealCount;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use App\Services\MealCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringAndCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $adminA;

    protected User $adminB;

    protected User $tiffinUser;

    protected Employee $empA1;

    protected User $empUserA1;

    protected TiffinService $tiffinService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);
        $this->companyB = Company::create(['name' => 'Beta Corp', 'code' => 'BETA1']);

        CompanySetting::create([
            'company_id' => $this->companyA->id,
            'cutoff_time' => '11:00:00',
            'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true,
            'meal_days' => [1, 2, 3, 4, 5],
        ]);

        CompanySetting::create([
            'company_id' => $this->companyB->id,
            'cutoff_time' => '11:00:00',
            'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true,
            'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->tiffinService = TiffinService::create([
            'name' => 'Super Tiffin',
            'email' => 'super@tiffin.com',
            'phone' => '8887776665',
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffinService->id,
            'is_active' => true,
            // Explicit: assigned_at defaults to useCurrent(), which would make
            // scopeActiveOn() exclude this company for any earlier test date.
            'assigned_at' => '2026-09-01',
        ]);

        $this->adminA = User::create([
            'name' => 'Admin Alpha',
            'email' => 'admin@alpha.com',
            'password' => bcrypt('password'),
            'role' => 'company_admin',
            'company_id' => $this->companyA->id,
        ]);

        $this->adminB = User::create([
            'name' => 'Admin Beta',
            'email' => 'admin@beta.com',
            'password' => bcrypt('password'),
            'role' => 'company_admin',
            'company_id' => $this->companyB->id,
        ]);

        $this->tiffinUser = User::create([
            'name' => 'Vendor User',
            'email' => 'vendor@supertiffin.com',
            'password' => bcrypt('password'),
            'role' => 'tiffin_admin',
            'tiffin_service_id' => $this->tiffinService->id,
        ]);

        $this->empA1 = Employee::create([
            'company_id' => $this->companyA->id,
            'employee_code' => 'EMP101',
            'name' => 'Alice Staff',
            'email' => 'alice@alpha.com',
            'status' => 'active',
            'is_meal_eligible' => true,
        ]);

        $this->empUserA1 = User::create([
            'name' => 'Alice Staff',
            'email' => 'alice@alpha.com',
            'password' => bcrypt('password'),
            'role' => 'employee',
            'company_id' => $this->companyA->id,
            'login_code' => 'EMP101',
            'must_change_password' => false,
        ]);

        $this->empA1->update(['user_id' => $this->empUserA1->id]);
    }

    public function test_generator_creates_skips_on_matching_weekdays_and_is_idempotent(): void
    {
        // 2026-10-05 is Monday (ISO weekday 1)
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        $action = new CreateRecurringSkip;
        $action->execute($this->companyA, $this->empA1, 1, '2026-10-05', null, $this->adminA);

        $this->artisan('mealbells:generate-recurring-skips')->assertExitCode(0);

        $this->assertDatabaseHas('skips', [
            'company_id' => $this->companyA->id,
            'employee_id' => $this->empA1->id,
            'date' => '2026-10-05',
            'source' => 'recurring',
        ]);

        // Run second time -> Idempotent, count remains 1
        $this->artisan('mealbells:generate-recurring-skips')->assertExitCode(0);
        $this->assertDatabaseCount('skips', 1);
    }

    public function test_cancelled_recurring_skip_is_not_recreated(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        $action = new CreateRecurringSkip;
        $action->execute($this->companyA, $this->empA1, 1, '2026-10-05', null, $this->adminA);

        $this->artisan('mealbells:generate-recurring-skips');

        // Employee cancels recurring skip
        $skip = Skip::first();
        $this->actingAs($this->empUserA1)->delete("/employee/skips/{$skip->id}");

        $this->assertNotNull($skip->fresh()->cancelled_at);

        // Run generator again -> Should NOT recreate skip
        $this->artisan('mealbells:generate-recurring-skips');
        $this->assertDatabaseCount('skips', 1);
        $this->assertNotNull(Skip::first()->cancelled_at);
    }

    public function test_pause_cancels_future_recurring_skips_and_keeps_hr_skips_safe(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        // Create rule for Monday (1)
        $createRule = new CreateRecurringSkip;
        $rule = $createRule->execute($this->companyA, $this->empA1, 1, '2026-10-05', null, $this->adminA);

        // Record HR skip for future Monday 2026-10-12
        $recordSkip = new RecordSkip;
        $recordSkip->execute($this->companyA, $this->empA1, '2026-10-12', 'hr', 'Official Leave', $this->adminA);

        // Generate recurring skip for 2026-10-19
        $recordSkip->execute($this->companyA, $this->empA1, '2026-10-19', 'recurring', 'Rule skip', $this->adminA);

        // Pause rule
        $pauseAction = new PauseRecurringSkip;
        $pauseAction->execute($rule, $this->adminA);

        // 2026-10-19 recurring skip is cancelled
        $skipRecurring = Skip::where('date', '2026-10-19')->first();
        $this->assertNotNull($skipRecurring->cancelled_at);

        // 2026-10-12 HR skip remains ACTIVE (cancelled_at is null)
        $skipHr = Skip::where('date', '2026-10-12')->first();
        $this->assertNull($skipHr->cancelled_at);
    }

    public function test_duplicate_recurring_rule_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        $action = new CreateRecurringSkip;
        $action->execute($this->companyA, $this->empA1, 1, '2026-10-05', null, $this->adminA);

        $this->expectException(MealRuleViolation::class);
        $action->execute($this->companyA, $this->empA1, 1, '2026-10-12', null, $this->adminA);
    }

    public function test_employee_can_cancel_recurring_skip_but_not_leave(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        $recordSkip = new RecordSkip;
        $skipRec = $recordSkip->execute($this->companyA, $this->empA1, '2026-10-12', 'recurring')->skip;
        $skipLeave = $recordSkip->execute($this->companyA, $this->empA1, '2026-10-13', 'leave')->skip;

        // Employee can delete recurring skip
        $res1 = $this->actingAs($this->empUserA1)->delete("/employee/skips/{$skipRec->id}");
        $res1->assertSessionHasNoErrors();
        $this->assertNotNull($skipRec->fresh()->cancelled_at);

        // Employee CANNOT delete leave skip
        $res2 = $this->actingAs($this->empUserA1)->delete("/employee/skips/{$skipLeave->id}");
        $res2->assertSessionHasErrors('skip');
        $this->assertNull($skipLeave->fresh()->cancelled_at);
    }

    public function test_is_meal_day_returns_false_on_holiday_and_true_on_weekend_working_day(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        // 2026-10-05 is Monday (default meal day = true)
        $this->assertTrue(MealCalendar::isMealDay($this->companyA, '2026-10-05'));

        // Mark 2026-10-05 as holiday
        CompanyCalendarDay::create([
            'company_id' => $this->companyA->id,
            'date' => '2026-10-05',
            'type' => 'holiday',
            'note' => 'Diwali Festival',
        ]);

        $this->assertFalse(MealCalendar::isMealDay($this->companyA, '2026-10-05'));

        // 2026-10-10 is Saturday (default meal day = false)
        $this->assertFalse(MealCalendar::isMealDay($this->companyA, '2026-10-10'));

        // Mark 2026-10-10 as working_day
        CompanyCalendarDay::create([
            'company_id' => $this->companyA->id,
            'date' => '2026-10-10',
            'type' => 'working_day',
            'note' => 'Compensatory Working Day',
        ]);

        $this->assertTrue(MealCalendar::isMealDay($this->companyA, '2026-10-10'));
    }

    public function test_calendar_modification_rejected_on_past_or_locked_date(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        $action = new SetCalendarDay;

        // Past date
        try {
            $action->execute($this->companyA, '2026-10-01', 'holiday');
            $this->fail('Expected past date exception');
        } catch (MealRuleViolation $e) {
            $this->assertEquals('past_date', $e->getReasonCodeString());
        }

        // Locked date
        MealCount::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffinService->id,
            'date' => '2026-10-12',
            'base_eligible_count' => 10,
            'skip_count' => 0,
            'extra_count' => 0,
            'final_expected_count' => 10,
            'adjusted_total' => 10,
            'breakdown' => [],
            'status' => 'confirmed',
            'locked_at' => now(),
        ]);

        try {
            $action->execute($this->companyA, '2026-10-12', 'holiday');
            $this->fail('Expected locked date exception');
        } catch (MealRuleViolation $e) {
            $this->assertEquals('count_locked', $e->getReasonCodeString());
        }
    }

    public function test_vendor_preparation_view_shows_holiday_reason(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        CompanyCalendarDay::create([
            'company_id' => $this->companyA->id,
            'date' => '2026-10-05',
            'type' => 'holiday',
            'note' => 'Gandhi Jayanti',
        ]);

        $action = new BuildVendorPreparationView;
        $viewData = $action->execute($this->tiffinService, '2026-10-05');

        $compData = $viewData['companies'][0];
        $this->assertEquals('no_meal', $compData['status']);
        $this->assertEquals('holiday', $compData['reason']);
        $this->assertEquals('Gandhi Jayanti', $compData['note']);
    }

    public function test_tiffin_admin_cannot_access_calendar_routes(): void
    {
        $response = $this->actingAs($this->tiffinUser)->get('/company-admin/calendar');
        $response->assertStatus(403);
    }
}
