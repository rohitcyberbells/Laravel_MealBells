# MealBells Project Handover & Agent Context Guide

> **Note for AI Assistant / Agent**: Read this file first whenever resuming session on any system. It contains the complete architectural context, current project state, testing rules, and exact roadmap for next steps.

---

## 🚀 Quick Commands for Any System
```bash
# ALWAYS use vendor/bin/phpunit to run tests
vendor/bin/phpunit

# Code style formatter (run before declaring task completion)
vendor/bin/pint --dirty --format agent
```

---

## 📊 Milestone Completion Status

| Stage / Milestone | Feature Description | Status | Test Coverage |
| :--- | :--- | :---: | :--- |
| **Stage 0 & 1a-1f** | Core Demand Engine ($\text{Base} + \text{Extra} - \text{Skips}$), Lock, Cutoff, Scheduler | ✅ **DONE** | 23 Tests Passed |
| **1g** | Vendor Preparation View (`BuildVendorPreparationView`, Vue UI, Role Isolation) | ✅ **DONE** | 5 Tests Passed |
| **1h** | Cancel Actions & Bulk Skip (`CancelSkip`, `CancelExtraMeal`, `BulkRecordSkip`) | ✅ **DONE** | 4 Tests Passed |
| **1i** | Company Admin UI (CompanyAdminController, EmployeeController, SkipController, MealAdjustmentController, CompanySettingController) | ✅ **DONE** | 3 Tests Passed |
| **1j Part 1-3** | Central Architecture, Guards Order, Outcomes, PostCutoffChange Hardening | ✅ **DONE** | 21 Tests Passed |
| **B1 (1k)** | Leave / WFH CSV (`ValidateSkipCsv`, `ImportSkips`) | ✅ **DONE** | 12 Tests Passed |
| **B2 (1l)** | Summary, Anomaly & Review Engine (`PrepareDailyCountSummary`, `DetectCountAnomalies`, `ConfirmDailyCount`) | ✅ **DONE** | 15 Tests Passed |
| **B3 & B4** | Employee Logins & Employee Portal (`CreateEmployeeLogins`, `ResetEmployeePassword`, Login Flow) | ✅ **DONE** | 18 Tests Passed |
| **B5** | Recurring Skips & Company Calendar (`CreateRecurringSkip`, `SetCalendarDay`, generator command) | ✅ **DONE** | 15 Tests Passed |
| **B6** | Employee Meal Flow Verification, Self-Skip & Cancellation Rule Verification | ✅ **DONE** | 8 Tests Passed |
| **B7 (1m & 2g/2h)** | Adoption Metrics, Health Dashboard, Single Active Assignment DB Constraint, Notifications Queueing, Hardening & Privacy Sweep | ✅ **DONE** | 14 Tests Passed |

**Current Suite Status**: **133 Tests Passing Green** (`vendor/bin/phpunit`).

---

## 🏗️ Core Architecture & Conventions

### 1. Centralized Guards Order (`App\Services\MealGuard`)
All validation checks follow this single fixed order:
1. `cross_company` (`MealRuleReason::CROSS_COMPANY`)
2. `inactive_employee` (`MealRuleReason::INACTIVE_EMPLOYEE`) - `status != 'active'` or `is_meal_eligible == false`
3. `not_a_meal_day` (`MealRuleReason::NOT_A_MEAL_DAY`) - Check `MealCalendar::isMealDay($company, $date)` (includes company calendar overrides)
4. `past_date` (`MealRuleReason::PAST_DATE`) - `$targetCarbon < $todayLocal` (in company timezone)
5. `advance_limit_exceeded` (`MealRuleReason::ADVANCE_LIMIT_EXCEEDED`) - `$targetCarbon > $todayLocal + 60 days`
6. `count_locked` (`MealRuleReason::COUNT_LOCKED`) - Check `locked_at != null` via `lockForUpdate`
7. `cutoff_passed` (`MealRuleReason::CUTOFF_PASSED`) - Check `MealCutoff::hasCutoffPassed`
8. `invalid_quantity` / `invalid_type` / `invalid_source`

### 2. First-Source-Wins & Skip Outcomes
- `RecordSkip::execute()` returns a `SkipResult` containing `Skip $skip` and `SkipOutcome $outcome`.
- **Skip Outcomes (`App\Enums\SkipOutcome`)**:
  - `CREATED`: Brand new skip record created.
  - `REACTIVATED`: Previously cancelled skip reactivated by manual source (`hr`, `self`).
  - `ALREADY_SKIPPED`: Active skip already exists; original source and reason are preserved.
  - `BLOCKED_CANCELLED`: Auto source (`leave`, `wfh`, `recurring`, `link`) attempted to touch a cancelled skip; row remains cancelled.

### 3. Employee Cancel Rule
- Employees can ONLY cancel skips with `source = 'self'` or `'recurring'`.
- Skips with `source = 'hr'`, `'leave'`, or `'wfh'` CANNOT be cancelled by employees (rejected with `employee_cannot_cancel_system_skip`).

### 4. Non-MVP / Deferred Features (Not in current MVP)
- One-tap email skip links (`/skip/{token}`) - *Planned / Deferred*
- Daily meal reminders - *Planned / Deferred*
- Public skip tokens & Unsubscribe flow - *Planned / Deferred*

---

## 📦 Key Directory Map
- `app/Services/MealGuard.php` - Fixed order validation service.
- `app/Services/MealCutoff.php` - Centralized cutoff calculations.
- `app/Services/MealCalendar.php` - Meal-day calendar check (with holiday/working day overrides).
- `app/Enums/MealRuleReason.php` - Business rule reason codes (15 codes).
- `app/Actions/Company/BuildAdoptionReport.php` - Adoption aggregates action.
- `app/Http/Controllers/SuperAdmin/HealthController.php` - System health controller.
- `tests/Feature/FinalHardeningTest.php` - B7 Hardening & privacy test suite.
