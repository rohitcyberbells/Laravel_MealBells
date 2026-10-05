<?php

namespace Tests\Feature;

use App\Actions\Employee\ValidateEmployeeCsv;
use App\Actions\Meal\BuildVendorPreparationView;
use App\Actions\Meal\BulkRecordSkip;
use App\Actions\Meal\CancelSkip;
use App\Actions\Meal\ConfirmDailyCount;
use App\Actions\Meal\CreateDailyOverride;
use App\Actions\Meal\RecordExtraMeal;
use App\Actions\Meal\RecordPostCutoffChange;
use App\Actions\Meal\RecordSkip;
use App\Enums\MealRuleReason;
use App\Enums\SkipOutcome;
use App\Events\DailyCountConfirmed;
use App\Exceptions\MealRuleViolation;
use App\Http\Controllers\SuperAdmin\SuperAdminController;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\TiffinService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        // Set test clock to a Wednesday (2026-10-07) so yesterday (Tuesday 2026-10-06) is a working meal day
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00'));

        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        CompanySetting::create(['company_id' => $company->id, 'cutoff_time' => '18:00:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5, 6, 7]]);
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

        Carbon::setTestNow();
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
        // Lock test time to Thursday 2026-10-01 so Oct 02 (Fri) and Oct 03 (Sat) are always future dates
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00'));

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

        Carbon::setTestNow();
    }

    /**
     * Part 3 Test 1: tiffin_admin => FORBIDDEN_ROLE
     */
    public function test_post_cutoff_change_forbidden_role_for_tiffin_admin(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $tiffinUser = User::create(['name' => 'Tiffin Admin', 'email' => 'tiffin@vendor.com', 'password' => bcrypt('password'), 'role' => 'tiffin_admin']);

        $action = new RecordPostCutoffChange;

        try {
            $action->execute($company, Carbon::today()->toDateString(), 5, 'Late order', $tiffinUser);
            $this->fail('Expected MealRuleViolation for tiffin_admin role.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::FORBIDDEN_ROLE, $e->getReasonCode());
        }
    }

    /**
     * Part 3 Test 2: super_admin => FORBIDDEN_ROLE
     */
    public function test_post_cutoff_change_forbidden_role_for_super_admin(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $superAdminUser = User::create(['name' => 'Super Admin', 'email' => 'super@mealbells.com', 'password' => bcrypt('password'), 'role' => 'super_admin']);

        $action = new RecordPostCutoffChange;

        try {
            $action->execute($company, Carbon::today()->toDateString(), 5, 'Override', $superAdminUser);
            $this->fail('Expected MealRuleViolation for super_admin role.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::FORBIDDEN_ROLE, $e->getReasonCode());
        }
    }

    /**
     * Part 3 Test 3: doosri company ka company_admin => CROSS_COMPANY
     */
    public function test_post_cutoff_change_cross_company_user_rejected(): void
    {
        $companyA = Company::create(['name' => 'Company A', 'address' => 'Addr A', 'contact_phone' => '1111111111']);
        $companyB = Company::create(['name' => 'Company B', 'address' => 'Addr B', 'contact_phone' => '2222222222']);
        $userB = User::create(['name' => 'Admin B', 'email' => 'admin@b.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $companyB->id]);

        $action = new RecordPostCutoffChange;

        try {
            $action->execute($companyA, Carbon::today()->toDateString(), 3, 'Cross company attempt', $userB);
            $this->fail('Expected MealRuleViolation for cross_company.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::CROSS_COMPANY, $e->getReasonCode());
        }
    }

    /**
     * Part 3 Test 4: snapshot locked nahi => COUNT_NOT_LOCKED
     */
    public function test_post_cutoff_change_unlocked_snapshot_rejected(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        $action = new RecordPostCutoffChange;

        try {
            $action->execute($company, Carbon::today()->toDateString(), 2, 'Unlocked change', $user);
            $this->fail('Expected MealRuleViolation for COUNT_NOT_LOCKED.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::COUNT_NOT_LOCKED, $e->getReasonCode());
        }
    }

    /**
     * Part 3 Test 5: quantity 0 => INVALID_QUANTITY
     */
    public function test_post_cutoff_change_zero_quantity_rejected(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => now()]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);
        Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'Staff 1', 'status' => 'active', 'is_meal_eligible' => true]);

        $date = Carbon::today()->toDateString();
        (new ConfirmDailyCount)->execute($company, $date, $user);

        $action = new RecordPostCutoffChange;

        try {
            $action->execute($company, $date, 0, 'Zero change', $user);
            $this->fail('Expected MealRuleViolation for zero quantity.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::INVALID_QUANTITY, $e->getReasonCode());
        }
    }

    /**
     * Part 3 Test 6: final 10, -15 => NEGATIVE_TOTAL; -10 allowed (total 0)
     */
    public function test_post_cutoff_change_negative_total_rejection_and_zero_total_allowed(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => now()]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        for ($i = 1; $i <= 10; $i++) {
            Employee::create(['company_id' => $company->id, 'employee_code' => "EMP{$i}", 'name' => "Staff {$i}", 'status' => 'active', 'is_meal_eligible' => true]);
        }

        $date = Carbon::today()->toDateString();
        $snapshot = (new ConfirmDailyCount)->execute($company, $date, $user);
        $this->assertEquals(10, $snapshot->final_expected_count);

        $action = new RecordPostCutoffChange;

        // Change -15 rejected (10 - 15 = -5 < 0)
        try {
            $action->execute($company, $date, -15, 'Too many cancelled', $user);
            $this->fail('Expected MealRuleViolation for NEGATIVE_TOTAL.');
        } catch (MealRuleViolation $e) {
            $this->assertEquals(MealRuleReason::NEGATIVE_TOTAL, $e->getReasonCode());
        }

        // Change -10 allowed (10 - 10 = 0)
        $change = $action->execute($company, $date, -10, 'All cancelled', $user);
        $this->assertEquals(-10, $change->change_quantity);
        $this->assertEquals(0, $snapshot->fresh()->adjusted_total);
    }

    /**
     * Part 3 Test 7: original snapshot columns change nahi hote, adjusted_total sahi aata hai
     */
    public function test_post_cutoff_change_preserves_original_snapshot_columns(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => now()]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        for ($i = 1; $i <= 5; $i++) {
            Employee::create(['company_id' => $company->id, 'employee_code' => "EMP{$i}", 'name' => "Staff {$i}", 'status' => 'active', 'is_meal_eligible' => true]);
        }

        $date = Carbon::today()->toDateString();
        $snapshot = (new ConfirmDailyCount)->execute($company, $date, $user);

        $baseCount = $snapshot->base_eligible_count;
        $skipCount = $snapshot->skip_count;
        $extraCount = $snapshot->extra_count;
        $finalCount = $snapshot->final_expected_count;

        (new RecordPostCutoffChange)->execute($company, $date, 4, 'Extra guests', $user);

        $freshSnapshot = $snapshot->fresh();

        // Original columns MUST be completely untouched
        $this->assertEquals($baseCount, $freshSnapshot->base_eligible_count);
        $this->assertEquals($skipCount, $freshSnapshot->skip_count);
        $this->assertEquals($extraCount, $freshSnapshot->extra_count);
        $this->assertEquals($finalCount, $freshSnapshot->final_expected_count);

        // Derived adjusted_total includes late change
        $this->assertEquals(9, $freshSnapshot->adjusted_total);
    }

    /**
     * Part 3 Test 8: do changes ke baad adjusted_total = final + sum
     */
    public function test_post_cutoff_change_sums_multiple_late_changes(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => now()]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        for ($i = 1; $i <= 8; $i++) {
            Employee::create(['company_id' => $company->id, 'employee_code' => "EMP{$i}", 'name' => "Staff {$i}", 'status' => 'active', 'is_meal_eligible' => true]);
        }

        $date = Carbon::today()->toDateString();
        $snapshot = (new ConfirmDailyCount)->execute($company, $date, $user);

        $action = new RecordPostCutoffChange;
        $action->execute($company, $date, +5, 'First addition', $user);
        $action->execute($company, $date, -2, 'Second reduction', $user);

        // adjusted_total = 8 + 5 - 2 = 11
        $this->assertEquals(11, $snapshot->fresh()->adjusted_total);
    }

    /**
     * Part 3 Test 9: ek input jo 2 rules todta hai (tiffin_admin + unlocked): FORBIDDEN_ROLE pehle
     */
    public function test_post_cutoff_change_evaluates_forbidden_role_before_count_not_locked(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $tiffinUser = User::create(['name' => 'Tiffin Admin', 'email' => 'vendor@tiffin.com', 'password' => bcrypt('password'), 'role' => 'tiffin_admin']);

        $action = new RecordPostCutoffChange;

        try {
            // Target date snapshot is unlocked AND user is tiffin_admin
            $action->execute($company, Carbon::today()->toDateString(), 3, 'Forbidden & Unlocked', $tiffinUser);
            $this->fail('Expected MealRuleViolation.');
        } catch (MealRuleViolation $e) {
            // Must return FORBIDDEN_ROLE (Rule 1), not COUNT_NOT_LOCKED (Rule 3)
            $this->assertEquals(MealRuleReason::FORBIDDEN_ROLE, $e->getReasonCode());
        }
    }

    /**
     * Fix 1: Timezone test: At UTC 20:00 (which is 01:30 AM IST next day), "today" in Asia/Kolkata resolves correctly.
     */
    public function test_timezone_fix_vendor_prep_view_resolves_local_today_at_utc_night(): void
    {
        // 2026-10-01 20:00:00 UTC == 2026-10-02 01:30:00 Asia/Kolkata
        Carbon::setTestNow(Carbon::parse('2026-10-01 20:00:00 UTC'));

        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => now()]);
        CompanySetting::create(['company_id' => $company->id, 'cutoff_time' => '10:30:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5, 6, 7]]);

        $action = new BuildVendorPreparationView;
        $viewData = $action->execute($tiffin, '2026-10-02');

        $this->assertEquals('2026-10-02', $viewData['date']);

        Carbon::setTestNow();
    }

    /**
     * Fix 2: attendance_source validation: 'integrated' passes, 'csv' is rejected.
     */
    public function test_attendance_source_integrated_passes_and_csv_rejected(): void
    {
        $action = new ValidateEmployeeCsv;

        $rows = [
            [
                'employee_code' => 'EMP1',
                'name' => 'Integrated Staff',
                'attendance_source' => 'integrated',
            ],
            [
                'employee_code' => 'EMP2',
                'name' => 'Invalid Staff',
                'attendance_source' => 'csv',
            ],
        ];

        $result = $action->execute($rows);

        $this->assertCount(1, $result['valid_rows']);
        $this->assertEquals('EMP1', $result['valid_rows'][0]['employee_code']);
        $this->assertEquals('integrated', $result['valid_rows'][0]['attendance_source']);

        $this->assertCount(1, $result['errors']);
        $this->assertEquals('attendance_source', $result['errors'][0]['field']);
    }

    /**
     * Fix 3: DailyCountConfirmed event is NOT dispatched if transaction rolls back.
     */
    public function test_daily_count_confirmed_event_not_dispatched_on_transaction_rollback(): void
    {
        Event::fake();

        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => now()]);
        CompanySetting::create(['company_id' => $company->id, 'cutoff_time' => '18:00:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5]]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);
        Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'John', 'status' => 'active', 'is_meal_eligible' => true]);

        $date = Carbon::today()->toDateString();

        try {
            DB::transaction(function () use ($company, $date, $user) {
                (new ConfirmDailyCount)->execute($company, $date, $user);
                throw new \Exception('Forced Transaction Rollback');
            });
        } catch (\Exception $e) {
            $this->assertEquals('Forced Transaction Rollback', $e->getMessage());
        }

        Event::assertNotDispatched(DailyCountConfirmed::class);
    }

    /**
     * Fix 4: SuperAdminController generates random passwords and hardcoded 'password' login fails.
     */
    public function test_super_admin_creates_random_passwords_and_hardcoded_password_login_fails(): void
    {
        $superAdmin = User::create(['name' => 'Super', 'email' => 'super@mb.com', 'password' => bcrypt('pass'), 'role' => 'super_admin']);
        $this->actingAs($superAdmin);

        $controller = new SuperAdminController;

        // Create Company 1
        $request1 = Request::create('/super-admin/companies', 'POST', [
            'name' => 'Company One',
            'address' => 'Address One',
            'contact_phone' => '9876543210',
            'admin_name' => 'Admin One',
            'admin_email' => 'admin1@comp1.com',
        ]);
        $controller->storeCompany($request1);

        // Create Company 2
        $request2 = Request::create('/super-admin/companies', 'POST', [
            'name' => 'Company Two',
            'address' => 'Address Two',
            'contact_phone' => '9876543211',
            'admin_name' => 'Admin Two',
            'admin_email' => 'admin2@comp2.com',
        ]);
        $controller->storeCompany($request2);

        $user1 = User::where('email', 'admin1@comp1.com')->first();
        $user2 = User::where('email', 'admin2@comp2.com')->first();

        $this->assertNotNull($user1);
        $this->assertNotNull($user2);

        // Assert two users have different password hashes
        $this->assertNotEquals($user1->password, $user2->password);

        // Assert must_change_password is true
        $this->assertTrue($user1->must_change_password);
        $this->assertTrue($user2->must_change_password);

        // Assert hardcoded 'password' login fails for both users
        $this->assertFalse(Auth::attempt(['email' => 'admin1@comp1.com', 'password' => 'password']));
        $this->assertFalse(Auth::attempt(['email' => 'admin2@comp2.com', 'password' => 'password']));
    }

    /**
     * Prompt A Test 1: must_change_password middleware enforces password change redirect on direct URL access.
     */
    public function test_must_change_password_middleware_redirects_direct_dashboard_access(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $user = User::create([
            'name' => 'Pending User',
            'email' => 'pending@acme.com',
            'password' => bcrypt('secret123'),
            'role' => 'company_admin',
            'company_id' => $company->id,
            'must_change_password' => true,
        ]);

        $this->actingAs($user);

        // Direct access to dashboard should redirect to change-password
        $response = $this->get('/company-admin/dashboard');
        $response->assertRedirect(route('password.change'));
    }

    /**
     * Prompt A Test 2: Super Admin reset password action generates new random password and sets flag.
     */
    public function test_super_admin_reset_password_action_resets_password_and_sets_must_change_flag(): void
    {
        $superAdmin = User::create(['name' => 'Super', 'email' => 'super2@mb.com', 'password' => bcrypt('pass'), 'role' => 'super_admin']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $targetUser = User::create([
            'name' => 'Target User',
            'email' => 'target@acme.com',
            'password' => bcrypt('oldpass'),
            'role' => 'company_admin',
            'company_id' => $company->id,
            'must_change_password' => false,
        ]);

        $this->actingAs($superAdmin);

        $response = $this->post("/super-admin/users/{$targetUser->id}/reset-password");
        $response->assertSessionHas('temporary_password');

        $targetUser->refresh();
        $this->assertTrue($targetUser->must_change_password);
        $this->assertFalse(Auth::attempt(['email' => 'target@acme.com', 'password' => 'oldpass']));
    }

    /**
     * Prompt A Test 4: CreateDailyOverride uses company timezone at UTC night.
     */
    public function test_create_daily_override_uses_company_timezone_at_utc_night(): void
    {
        // 2026-10-01 20:00:00 UTC == 2026-10-02 01:30:00 Asia/Kolkata
        Carbon::setTestNow(Carbon::parse('2026-10-01 20:00:00 UTC'));

        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => now()]);
        CompanySetting::create(['company_id' => $company->id, 'cutoff_time' => '10:30:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5, 6, 7]]);
        $tiffinUser = User::create(['name' => 'Chef', 'email' => 'chef@tiffin.com', 'password' => bcrypt('pass'), 'role' => 'tiffin_admin', 'tiffin_service_id' => $tiffin->id]);

        $action = new CreateDailyOverride;
        $override = $action->execute($tiffinUser, ['meal_description' => 'Rajma Rice']);

        $this->assertEquals('2026-10-02', $override->date);

        Carbon::setTestNow();
    }

    /**
     * Prompt A Test 6: ConfirmDailyCount unique constraint handling on sequential/race execution.
     */
    public function test_confirm_daily_count_handles_sequential_execution_without_crashing(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => now()]);
        CompanySetting::create(['company_id' => $company->id, 'cutoff_time' => '18:00:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5]]);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);
        Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'John', 'status' => 'active', 'is_meal_eligible' => true]);

        $date = Carbon::today()->toDateString();
        $action = new ConfirmDailyCount;

        $snapshot1 = $action->execute($company, $date, $user);
        $snapshot2 = $action->execute($company, $date, $user);

        $this->assertEquals($snapshot1->id, $snapshot2->id);
    }
}
