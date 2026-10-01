<?php

namespace Tests\Feature;

use App\Actions\Meal\BulkRecordSkip;
use App\Actions\Meal\CancelSkip;
use App\Actions\Meal\RecordExtraMeal;
use App\Actions\Meal\RecordSkip;
use App\Enums\MealRuleReason;
use App\Enums\SkipOutcome;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Inactive & ineligible employee => inactive_employee
     */
    public function test_inactive_and_ineligible_employee_rejected_with_inactive_employee_reason(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $inactiveEmp = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'John', 'status' => 'inactive', 'is_meal_eligible' => true]);
        $ineligibleEmp = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP2', 'name' => 'Jane', 'status' => 'active', 'is_meal_eligible' => false]);

        $action = new RecordSkip;
        $date = Carbon::tomorrow()->toDateString();

        // Inactive employee check
        try {
            $action->execute($company, $inactiveEmp, $date);
            $this->fail('Expected MealRuleViolation for inactive employee.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::INACTIVE_EMPLOYEE, $e->getReasonCode());
        }

        // Ineligible employee check
        try {
            $action->execute($company, $ineligibleEmp, $date);
            $this->fail('Expected MealRuleViolation for ineligible employee.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::INACTIVE_EMPLOYEE, $e->getReasonCode());
        }
    }

    /**
     * 2. Past date => past_date; aaj allowed; today+61 => advance_limit_exceeded; today+60 allowed
     */
    public function test_past_date_and_advance_limit_exceeded_guards(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $emp = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'John', 'status' => 'active', 'is_meal_eligible' => true]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        $action = new RecordSkip;

        // Past date rejected
        try {
            $action->execute($company, $emp, Carbon::yesterday()->toDateString(), 'hr', 'Past', $user);
            $this->fail('Expected MealRuleViolation for past date.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::PAST_DATE, $e->getReasonCode());
        }

        // Today allowed (before cutoff)
        $todayResult = $action->execute($company, $emp, Carbon::today()->toDateString(), 'hr', 'Today', $user);
        $this->assertEquals(SkipOutcome::CREATED, $todayResult->outcome);

        // Today + 60 days allowed
        $emp60 = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP60', 'name' => 'Fifty', 'status' => 'active', 'is_meal_eligible' => true]);
        $day60Result = $action->execute($company, $emp60, Carbon::today()->addDays(60)->toDateString(), 'hr', 'Day 60', $user);
        $this->assertEquals(SkipOutcome::CREATED, $day60Result->outcome);

        // Today + 61 days rejected
        try {
            $action->execute($company, $emp, Carbon::today()->addDays(61)->toDateString(), 'hr', 'Day 61', $user);
            $this->fail('Expected MealRuleViolation for advance limit exceeded.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::ADVANCE_LIMIT_EXCEEDED, $e->getReasonCode());
        }
    }

    /**
     * 3. Extra meal: 0, -1, 1.5, 101 => invalid_quantity; "5" allowed; 1 aur 100 allowed; bad type => invalid_type
     */
    public function test_extra_meal_quantity_and_type_validation(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        $action = new RecordExtraMeal;
        $date = Carbon::tomorrow()->toDateString();

        // 0 rejected
        try {
            $action->execute($company, $user, $date, 0);
            $this->fail('Expected MealRuleViolation for quantity 0.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::INVALID_QUANTITY, $e->getReasonCode());
        }

        // -1 rejected
        try {
            $action->execute($company, $user, $date, -1);
            $this->fail('Expected MealRuleViolation for negative quantity.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::INVALID_QUANTITY, $e->getReasonCode());
        }

        // 1.5 rejected
        try {
            $action->execute($company, $user, $date, 1.5);
            $this->fail('Expected MealRuleViolation for float quantity.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::INVALID_QUANTITY, $e->getReasonCode());
        }

        // 101 rejected
        try {
            $action->execute($company, $user, $date, 101);
            $this->fail('Expected MealRuleViolation for quantity exceeding max limit.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::INVALID_QUANTITY, $e->getReasonCode());
        }

        // "5" allowed
        $adj5 = $action->execute($company, $user, $date, '5', 'guest');
        $this->assertEquals(5, $adj5->quantity);

        // 1 allowed
        $adj1 = $action->execute($company, $user, $date, 1, 'visitor');
        $this->assertEquals(1, $adj1->quantity);

        // 100 allowed
        $adj100 = $action->execute($company, $user, $date, 100, 'other');
        $this->assertEquals(100, $adj100->quantity);

        // Bad type rejected
        try {
            $action->execute($company, $user, $date, 5, 'invalid_type_name');
            $this->fail('Expected MealRuleViolation for invalid type.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::INVALID_TYPE, $e->getReasonCode());
        }
    }

    /**
     * 4. Active duplicate: source/reason same, outcome already_skipped
     */
    public function test_active_duplicate_returns_already_skipped_without_changing_source_or_reason(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $emp = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'John', 'status' => 'active', 'is_meal_eligible' => true]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        $action = new RecordSkip;
        $date = Carbon::tomorrow()->toDateString();

        $res1 = $action->execute($company, $emp, $date, 'hr', 'Initial Reason', $user);
        $this->assertEquals(SkipOutcome::CREATED, $res1->outcome);

        $res2 = $action->execute($company, $emp, $date, 'leave', 'Attempt Overwrite', $user);
        $this->assertEquals(SkipOutcome::ALREADY_SKIPPED, $res2->outcome);
        $this->assertEquals($res1->skip->id, $res2->skip->id);
        $this->assertEquals('hr', $res2->skip->source);
        $this->assertEquals('Initial Reason', $res2->skip->reason);
    }

    /**
     * 5. Cancelled + hr => reactivated (same row id); cancelled + leave => blocked_cancelled
     */
    public function test_cancelled_skip_reactivation_rules(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $emp = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'John', 'status' => 'active', 'is_meal_eligible' => true]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        $action = new RecordSkip;
        $cancelAction = new CancelSkip;
        $date = Carbon::tomorrow()->toDateString();

        // Step 1: Create and cancel
        $res1 = $action->execute($company, $emp, $date, 'hr', 'Original', $user);
        $cancelAction->execute($company, $res1->skip, $user);

        // Step 2: Auto source ('leave') attempt -> blocked_cancelled
        $resAuto = $action->execute($company, $emp, $date, 'leave', 'Auto Leave', $user);
        $this->assertEquals(SkipOutcome::BLOCKED_CANCELLED, $resAuto->outcome);
        $this->assertNotNull($resAuto->skip->fresh()->cancelled_at);

        // Step 3: Manual source ('hr') attempt -> reactivated
        $resManual = $action->execute($company, $emp, $date, 'hr', 'HR Override', $user);
        $this->assertEquals(SkipOutcome::REACTIVATED, $resManual->outcome);
        $this->assertEquals($res1->skip->id, $resManual->skip->id);
        $this->assertNull($resManual->skip->fresh()->cancelled_at);
        $this->assertEquals('hr', $resManual->skip->source);
    }

    /**
     * 6. Ek input jo 2 rules todta hai (inactive + non-meal-day): pehla code (inactive_employee)
     */
    public function test_strict_guard_order_evaluates_inactive_employee_before_not_a_meal_day(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        CompanySetting::create(['company_id' => $company->id, 'cutoff_time' => '18:00:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5]]);
        $inactiveEmp = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'John', 'status' => 'inactive', 'is_meal_eligible' => true]);

        // Sunday (non-meal day)
        $sunday = Carbon::parse('2026-10-04')->toDateString();

        $action = new RecordSkip;

        try {
            $action->execute($company, $inactiveEmp, $sunday);
            $this->fail('Expected MealRuleViolation.');
        } catch (MealRuleViolation $e) {
            // Must return INACTIVE_EMPLOYEE, not NOT_A_MEAL_DAY
            $this->assertEquals(MealRuleReason::INACTIVE_EMPLOYEE, $e->getReasonCode());
        }
    }

    /**
     * 7. Cutoff boundary setTestNow: 10:29:59 allow, 10:30:00 reject
     */
    public function test_cutoff_boundary_time_enforcement(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        CompanySetting::create(['company_id' => $company->id, 'cutoff_time' => '10:30:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5, 6, 7]]);
        $emp1 = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'John', 'status' => 'active', 'is_meal_eligible' => true]);
        $emp2 = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP2', 'name' => 'Jane', 'status' => 'active', 'is_meal_eligible' => true]);

        $action = new RecordSkip;
        $todayStr = '2026-10-01'; // Thursday

        // 10:29:59 -> allowed
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:29:59', 'Asia/Kolkata'));
        $res1 = $action->execute($company, $emp1, $todayStr);
        $this->assertEquals(SkipOutcome::CREATED, $res1->outcome);

        // 10:30:00 -> rejected
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:30:00', 'Asia/Kolkata'));
        try {
            $action->execute($company, $emp2, $todayStr);
            $this->fail('Expected cutoff rejection at exactly 10:30:00.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::CUTOFF_PASSED, $e->getReasonCode());
        }

        Carbon::setTestNow();
    }

    /**
     * 8. Do companies alag timezone: ek ka cutoff nikla, doosre ka nahi
     */
    public function test_multi_company_timezone_cutoff_isolation(): void
    {
        $companyA = Company::create(['name' => 'Acme India', 'address' => 'India', 'contact_phone' => '1111111111']);
        CompanySetting::create(['company_id' => $companyA->id, 'cutoff_time' => '10:30:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5, 6, 7]]);
        $empA = Employee::create(['company_id' => $companyA->id, 'employee_code' => 'EMPA', 'name' => 'Staff A', 'status' => 'active', 'is_meal_eligible' => true]);

        $companyB = Company::create(['name' => 'Acme US', 'address' => 'USA', 'contact_phone' => '2222222222']);
        CompanySetting::create(['company_id' => $companyB->id, 'cutoff_time' => '10:30:00', 'timezone' => 'America/New_York', 'meal_days' => [1, 2, 3, 4, 5, 6, 7]]);
        $empB = Employee::create(['company_id' => $companyB->id, 'employee_code' => 'EMPB', 'name' => 'Staff B', 'status' => 'active', 'is_meal_eligible' => true]);

        $action = new RecordSkip;

        // Set test now to 10:31:00 AM Kolkata time (which is 01:01 AM NY time on Oct 1)
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:31:00', 'Asia/Kolkata'));

        // Company A local date '2026-10-01' -> cutoff HAS passed (10:31 >= 10:30)
        try {
            $action->execute($companyA, $empA, '2026-10-01');
            $this->fail('Expected Company A skip to fail due to passed cutoff.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::CUTOFF_PASSED, $e->getReasonCode());
        }

        // Company B local date '2026-10-01' -> cutoff HAS NOT passed (01:01 AM < 10:30 AM NY time)
        $resB = $action->execute($companyB, $empB, '2026-10-01');
        $this->assertEquals(SkipOutcome::CREATED, $resB->outcome);

        Carbon::setTestNow();
    }

    /**
     * 9. Cancel guards: CancelSkip inactive employee par bhi chale (employee baad mein inactive hua)
     */
    public function test_cancel_skip_succeeds_even_if_employee_became_inactive_later(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $emp = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'John', 'status' => 'active', 'is_meal_eligible' => true]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        $action = new RecordSkip;
        $cancelAction = new CancelSkip;
        $date = Carbon::tomorrow()->toDateString();

        $skipRes = $action->execute($company, $emp, $date, 'hr', 'Leaving', $user);

        // Employee becomes inactive after skip was recorded
        $emp->update(['status' => 'inactive']);

        // CancelSkip should still succeed without throwing INACTIVE_EMPLOYEE error
        $cancelledSkip = $cancelAction->execute($company, $skipRes->skip, $user);
        $this->assertNotNull($cancelledSkip->cancelled_at);
    }

    /**
     * 10. Invalid source => invalid_source
     */
    public function test_invalid_skip_source_rejected_with_invalid_source_reason(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $emp = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'John', 'status' => 'active', 'is_meal_eligible' => true]);

        $action = new RecordSkip;

        try {
            $action->execute($company, $emp, Carbon::tomorrow()->toDateString(), 'invalid_custom_source');
            $this->fail('Expected MealRuleViolation for invalid source.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::INVALID_SOURCE, $e->getReasonCode());
        }
    }

    /**
     * 11. Extra meal user doosri company ka => cross_company
     */
    public function test_extra_meal_cross_company_user_rejected(): void
    {
        $companyA = Company::create(['name' => 'Company A', 'address' => 'Addr A', 'contact_phone' => '1111111111']);
        $companyB = Company::create(['name' => 'Company B', 'address' => 'Addr B', 'contact_phone' => '2222222222']);
        $userB = User::create(['name' => 'Admin B', 'email' => 'admin@b.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $companyB->id]);

        $action = new RecordExtraMeal;

        try {
            $action->execute($companyA, $userB, Carbon::tomorrow()->toDateString(), 5, 'guest');
            $this->fail('Expected MealRuleViolation for cross company user.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::CROSS_COMPANY, $e->getReasonCode());
        }
    }

    /**
     * 12. Bulk: 4 outcomes + rejected list + non_meal_days count
     */
    public function test_bulk_skip_tracks_outcomes_rejected_list_and_non_meal_days(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        CompanySetting::create(['company_id' => $company->id, 'cutoff_time' => '18:00:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5]]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        $empActive = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'Active', 'status' => 'active', 'is_meal_eligible' => true]);
        $empInactive = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP2', 'name' => 'Inactive', 'status' => 'inactive', 'is_meal_eligible' => true]);

        // Friday: 2026-10-02 (Meal day), Saturday: 2026-10-03 (Non-meal day)
        $dates = ['2026-10-02', '2026-10-03'];

        $action = new BulkRecordSkip;

        // Run 1: empActive & empInactive across Fri & Sat
        $summary1 = $action->execute($company, [$empActive->id, $empInactive->id], $dates, 'hr', 'Bulk 1', $user);

        $this->assertEquals(4, $summary1['total_processed']);
        $this->assertEquals(1, $summary1['created_count']); // empActive on Fri
        $this->assertEquals(2, $summary1['non_meal_day_count']); // Fri & Sat non-meal days
        $this->assertEquals(1, $summary1['rejected_count']); // empInactive on Fri (inactive_employee)

        // Run 2: empActive on Fri again (already_skipped)
        $summary2 = $action->execute($company, [$empActive->id], ['2026-10-02'], 'hr', 'Bulk 2', $user);
        $this->assertEquals(1, $summary2['already_skipped_count']);
    }
}
