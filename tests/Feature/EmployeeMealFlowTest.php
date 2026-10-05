<?php

namespace Tests\Feature;

use App\Actions\Meal\CalculateExpectedMeals;
use App\Actions\Meal\RecordSkip;
use App\Models\Company;
use App\Models\CompanyCalendarDay;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\DailyOverrides;
use App\Models\Employee;
use App\Models\MealCount;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeMealFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected TiffinService $tiffinService;

    protected Employee $empA1;

    protected Employee $empA2;

    protected Employee $empB1;

    protected User $empUserA1;

    protected User $empUserA2;

    protected User $empUserB1;

    protected User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create([
            'name' => 'Alpha Corp',
            'code' => 'ALPHA1',
            'status' => 'active',
        ]);

        CompanySetting::create([
            'company_id' => $this->companyA->id,
            'cutoff_time' => '11:00:00',
            'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true,
        ]);

        $this->companyB = Company::create([
            'name' => 'Beta Corp',
            'code' => 'BETA02',
            'status' => 'active',
        ]);

        CompanySetting::create([
            'company_id' => $this->companyB->id,
            'cutoff_time' => '11:00:00',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->tiffinService = TiffinService::create([
            'name' => 'Delicious Tiffins',
            'code' => 'DELISH',
            'status' => 'active',
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffinService->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $this->adminA = User::create([
            'name' => 'Admin Alpha',
            'email' => 'admin@alpha.com',
            'password' => bcrypt('password'),
            'role' => 'company_admin',
            'company_id' => $this->companyA->id,
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

        $this->empA2 = Employee::create([
            'company_id' => $this->companyA->id,
            'employee_code' => 'EMP102',
            'name' => 'Bob Staff',
            'email' => 'bob@alpha.com',
            'status' => 'active',
            'is_meal_eligible' => true,
        ]);

        $this->empUserA2 = User::create([
            'name' => 'Bob Staff',
            'email' => 'bob@alpha.com',
            'password' => bcrypt('password'),
            'role' => 'employee',
            'company_id' => $this->companyA->id,
            'login_code' => 'EMP102',
            'must_change_password' => false,
        ]);
        $this->empA2->update(['user_id' => $this->empUserA2->id]);

        $this->empB1 = Employee::create([
            'company_id' => $this->companyB->id,
            'employee_code' => 'EMPB1',
            'name' => 'Charlie Beta',
            'email' => 'charlie@beta.com',
            'status' => 'active',
            'is_meal_eligible' => true,
        ]);

        $this->empUserB1 = User::create([
            'name' => 'Charlie Beta',
            'email' => 'charlie@beta.com',
            'password' => bcrypt('password'),
            'role' => 'employee',
            'company_id' => $this->companyB->id,
            'login_code' => 'EMPB1',
            'must_change_password' => false,
        ]);
        $this->empB1->update(['user_id' => $this->empUserB1->id]);
    }

    public function test_employee_self_skip_reduces_expected_meals_count(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        // Initially expected meals = 2 (Alice & Bob)
        $calcAction = new CalculateExpectedMeals;
        $initial = $calcAction->execute($this->companyA, '2026-10-05');
        $this->assertEquals(2, $initial['final_expected_count']);
        $this->assertEquals(0, $initial['skip_count']);

        // Alice self-skips from employee portal
        $response = $this->actingAs($this->empUserA1)->post('/employee/skips', [
            'date' => '2026-10-05',
            'reason' => 'Not feeling well',
        ]);
        $response->assertSessionHasNoErrors();

        // Expected meals reduced to 1
        $updated = $calcAction->execute($this->companyA, '2026-10-05');
        $this->assertEquals(1, $updated['final_expected_count']);
        $this->assertEquals(1, $updated['skip_count']);
    }

    public function test_employee_can_cancel_self_and_recurring_skips_restoring_count(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        $recordSkip = new RecordSkip;
        $skipSelf = $recordSkip->execute($this->companyA, $this->empA1, '2026-10-05', 'self')->skip;
        $skipRec = $recordSkip->execute($this->companyA, $this->empA2, '2026-10-05', 'recurring')->skip;

        // Count is 0
        $calcAction = new CalculateExpectedMeals;
        $this->assertEquals(0, $calcAction->execute($this->companyA, '2026-10-05')['final_expected_count']);

        // Alice cancels self skip
        $res1 = $this->actingAs($this->empUserA1)->delete("/employee/skips/{$skipSelf->id}");
        $res1->assertSessionHasNoErrors();
        $this->assertNotNull($skipSelf->fresh()->cancelled_at);

        // Bob cancels recurring skip
        $res2 = $this->actingAs($this->empUserA2)->delete("/employee/skips/{$skipRec->id}");
        $res2->assertSessionHasNoErrors();
        $this->assertNotNull($skipRec->fresh()->cancelled_at);

        // Expected meals restored to 2
        $this->assertEquals(2, $calcAction->execute($this->companyA, '2026-10-05')['final_expected_count']);
    }

    public function test_employee_cannot_cancel_hr_leave_or_wfh_system_skips(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        $recordSkip = new RecordSkip;
        $skipHr = $recordSkip->execute($this->companyA, $this->empA1, '2026-10-05', 'hr')->skip;
        $skipLeave = $recordSkip->execute($this->companyA, $this->empA1, '2026-10-06', 'leave')->skip;
        $skipWfh = $recordSkip->execute($this->companyA, $this->empA1, '2026-10-07', 'wfh')->skip;

        // Try cancelling HR skip
        $res1 = $this->actingAs($this->empUserA1)->delete("/employee/skips/{$skipHr->id}");
        $res1->assertSessionHasErrors('skip');
        $this->assertNull($skipHr->fresh()->cancelled_at);

        // Try cancelling Leave skip
        $res2 = $this->actingAs($this->empUserA1)->delete("/employee/skips/{$skipLeave->id}");
        $res2->assertSessionHasErrors('skip');
        $this->assertNull($skipLeave->fresh()->cancelled_at);

        // Try cancelling WFH skip
        $res3 = $this->actingAs($this->empUserA1)->delete("/employee/skips/{$skipWfh->id}");
        $res3->assertSessionHasErrors('skip');
        $this->assertNull($skipWfh->fresh()->cancelled_at);
    }

    public function test_forgotten_skip_defaults_to_take_and_no_scheduler_creates_unwanted_skips(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        // Employee does nothing -> no skips exist
        $this->assertDatabaseCount('skips', 0);

        // Expected meals counts employee as Take
        $calcAction = new CalculateExpectedMeals;
        $numbers = $calcAction->execute($this->companyA, '2026-10-05');
        $this->assertEquals(2, $numbers['final_expected_count']);

        // Run all console commands
        $this->artisan('mealbells:generate-recurring-skips')->assertExitCode(0);

        // Still 0 skips created
        $this->assertDatabaseCount('skips', 0);
    }

    public function test_admin_correction_records_skip_adjusts_count_and_prevents_employee_deletion(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        // Admin records WFH skip for Alice
        $res = $this->actingAs($this->adminA)->post('/company-admin/skips', [
            'employee_id' => $this->empA1->id,
            'date' => '2026-10-05',
            'source' => 'wfh',
            'reason' => 'Working from home',
        ]);
        $res->assertSessionHasNoErrors();

        $skip = Skip::where('employee_id', $this->empA1->id)->where('date', '2026-10-05')->first();
        $this->assertNotNull($skip);
        $this->assertEquals('wfh', $skip->source);

        // Expected meals adjusted
        $calcAction = new CalculateExpectedMeals;
        $this->assertEquals(1, $calcAction->execute($this->companyA, '2026-10-05')['final_expected_count']);

        // Employee cannot delete it
        $delRes = $this->actingAs($this->empUserA1)->delete("/employee/skips/{$skip->id}");
        $delRes->assertSessionHasErrors('skip');
        $this->assertNull($skip->fresh()->cancelled_at);
    }

    public function test_employee_self_skip_guards_reject_invalid_operations(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 12:00:00', 'Asia/Kolkata')); // Past 11:00 AM cutoff

        // 1. Cutoff passed guard
        $res1 = $this->actingAs($this->empUserA1)->post('/employee/skips', [
            'date' => '2026-10-05',
        ]);
        $res1->assertSessionHasErrors('skip');

        // 2. Non-meal day guard (2026-10-10 is Saturday)
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));
        $res2 = $this->actingAs($this->empUserA1)->post('/employee/skips', [
            'date' => '2026-10-10',
        ]);
        $res2->assertSessionHasErrors('skip');

        // 3. Locked date guard
        MealCount::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffinService->id,
            'date' => '2026-10-06',
            'base_eligible_count' => 2,
            'skip_count' => 0,
            'extra_count' => 0,
            'final_expected_count' => 2,
            'adjusted_total' => 2,
            'breakdown' => [],
            'status' => 'confirmed',
            'locked_at' => now(),
        ]);
        $res3 = $this->actingAs($this->empUserA1)->post('/employee/skips', [
            'date' => '2026-10-06',
        ]);
        $res3->assertSessionHasErrors('skip');

        // 4. Past date guard
        $res4 = $this->actingAs($this->empUserA1)->post('/employee/skips', [
            'date' => '2026-10-01',
        ]);
        $res4->assertSessionHasErrors('skip');

        // 5. Advance limit exceeded guard (> 60 days)
        $res5 = $this->actingAs($this->empUserA1)->post('/employee/skips', [
            'date' => '2026-12-20',
        ]);
        $res5->assertSessionHasErrors('skip');
    }

    public function test_tenant_and_ownership_isolation_prevents_unauthorized_deletion(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        $recordSkip = new RecordSkip;
        $skipAlice = $recordSkip->execute($this->companyA, $this->empA1, '2026-10-05', 'self')->skip;
        $skipCharlie = $recordSkip->execute($this->companyB, $this->empB1, '2026-10-05', 'self')->skip;

        // Bob (same company) cannot delete Alice's skip (returns 404)
        $res1 = $this->actingAs($this->empUserA2)->delete("/employee/skips/{$skipAlice->id}");
        $res1->assertStatus(404);
        $this->assertNull($skipAlice->fresh()->cancelled_at);

        // Alice cannot delete Charlie's skip (other company, returns 404)
        $res2 = $this->actingAs($this->empUserA1)->delete("/employee/skips/{$skipCharlie->id}");
        $res2->assertStatus(404);
        $this->assertNull($skipCharlie->fresh()->cancelled_at);
    }

    public function test_dashboard_props_include_override_and_holiday_data(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-05 08:00:00', 'Asia/Kolkata'));

        // Mark today 2026-10-05 as holiday
        CompanyCalendarDay::create([
            'company_id' => $this->companyA->id,
            'date' => '2026-10-05',
            'type' => 'holiday',
            'note' => 'Gandhi Jayanti Holiday',
        ]);

        // Add daily override for tomorrow 2026-10-06
        DailyOverrides::create([
            'tiffin_service_id' => $this->tiffinService->id,
            'date' => '2026-10-06',
            'meal_description' => 'Special Festive Thali',
            'created_by' => $this->adminA->id,
        ]);

        $response = $this->actingAs($this->empUserA1)->get('/employee/dashboard');
        $response->assertStatus(200);

        $pageProps = $response->getOriginalContent()->getData()['page']['props'];

        // Verify holiday info in today prop
        $this->assertFalse($pageProps['today']['is_meal_day']);
        $this->assertEquals('holiday', $pageProps['today']['calendar_day']['type']);
        $this->assertEquals('Gandhi Jayanti Holiday', $pageProps['today']['calendar_day']['note']);

        // Verify override in next_7_days prop for 2026-10-06
        $tomorrowProp = collect($pageProps['next_7_days'])->firstWhere('date', '2026-10-06');
        $this->assertNotNull($tomorrowProp);
        $this->assertTrue($tomorrowProp['has_override']);
        $this->assertEquals('Special Festive Thali', $tomorrowProp['meal']);
    }
}
