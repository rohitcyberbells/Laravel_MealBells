# MealBells Project Handover & Agent Context Guide

> **Note for AI Assistant / Agent**: Read this file first whenever resuming session on any system (Home/Office). It contains the complete architectural context, current project state, testing rules, and exact roadmap for next steps.

---

## 🚀 Quick Commands for Any System
```bash
# ALWAYS use vendor/bin/phpunit to run tests (do NOT run php artisan test due to macOS Herd proc_open tty limitation)
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
| **1i** | Company Admin Controllers & Inertia Routes (CSV Import, Settings, Employees) | ✅ **DONE** | 3 Tests Passed |
| **1j Part 1** | Central Architecture (`MealRuleReason`, `MealRuleViolation`, `MealCutoff`, Config) | ✅ **DONE** | Integrated |
| **1j Part 2** | Guards Fixed Order, First-Source-Wins (`SkipResult`), Limits, Concurrency, Hardening | ✅ **DONE** | 12 Hardening Tests Passed |
| **1j Part 3** | `RecordPostCutoffChange` Hardening (`lockForUpdate`, `negative_total`, `forbidden_role`) | ⏳ **NEXT TASK** | Pending |
| **1k** | Leave / WFH CSV (`ValidateSkipCsv`, `ImportSkips`) | ⏳ Pending | - |
| **1l** | Summary, Anomaly & Review Engine | ⏳ Pending | - |
| **1m** | Production Readiness & Final Verification | ⏳ Pending | - |

**Current Suite Status**: **47 Tests Passing Green** (`vendor/bin/phpunit`).

---

## 🏗️ Core Architecture & Conventions

### 1. Centralized Guards Order (`App\Services\MealGuard`)
All validation checks must follow this single fixed order:
1. `cross_company` (`MealRuleReason::CROSS_COMPANY`)
2. `inactive_employee` (`MealRuleReason::INACTIVE_EMPLOYEE`) - `status != 'active'` or `is_meal_eligible == false`
3. `not_a_meal_day` (`MealRuleReason::NOT_A_MEAL_DAY`) - Check `MealCalendar::isMealDay($company, $date)`
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
  - `ALREADY_SKIPPED`: Active skip already exists; source and reason are preserved.
  - `BLOCKED_CANCELLED`: Auto source (`leave`, `wfh`, `recurring`, `link`) attempted to touch a cancelled skip; row remains cancelled, no exception thrown.
- **Race Condition Handling**: DB transaction catches unique key `QueryException` on `(employee_id, date)` and safely returns `ALREADY_SKIPPED`.

### 3. Extra Meal Validation Limits
- Accepts integer or integer-like strings (e.g., `"5"`).
- Rejects floats (`1.5`), zero (`0`), negatives (`-1`), and non-numerics with `INVALID_QUANTITY`.
- Max limit: `100` (`config('mealbells.max_extra_meals')`).
- Types allowed: `guest`, `visitor`, `other` (`config('mealbells.allowed_extra_types')`).

---

## 🎯 Next Steps (Instructions for Milestone 1j Part 3)

### Task: PostCutoffChange Hardening (`app/Actions/Meal/RecordPostCutoffChange.php`)
When completing Part 3:
1. **Guards & Permissions**:
   - Tiffin Admin cannot invoke `RecordPostCutoffChange` (`MealRuleReason::FORBIDDEN_ROLE`).
   - Must verify company owns the lock/count (`CROSS_COMPANY`).
   - Date count MUST be locked (`MealRuleReason::COUNT_NOT_LOCKED` if `locked_at` is null).
2. **Concurrency & Lock**:
   - Use `MealCount::where(...)->lockForUpdate()` inside a DB transaction on `meal_counts` row.
3. **Validation**:
   - Check `$newAdjustedTotal >= 0`, otherwise throw `MealRuleViolation` with reason code `NEGATIVETOTAL` (`MealRuleReason::NEGATIVE_TOTAL`).
4. **Audit Trail Integrity**:
   - Original snapshot columns (`base_count`, `skip_count`, `extra_count`, `final_expected_count`) MUST NOT change.
   - Insert audit record into `meal_count_changes` table (`original_count`, `new_count`, `reason`, `changed_by`).
   - Update `meal_counts.adjusted_total`.
5. **Testing**:
   - Add feature tests in `tests/Feature/HardeningTest.php` for Part 3 rules.

---

## 📦 Key Directory Map
- `app/Services/MealGuard.php` - Fixed order validation service.
- `app/Services/MealCutoff.php` - Centralized cutoff calculations.
- `app/Services/MealCalendar.php` - Meal-day calendar check.
- `app/Enums/MealRuleReason.php` - All 13 business rule reason codes.
- `app/Exceptions/MealRuleViolation.php` - Domain exception class.
- `app/Support/SkipResult.php` - Result DTO for `RecordSkip`.
- `app/Enums/SkipOutcome.php` - 4 outcome types for skips.
- `app/Actions/Meal/` - Core business actions (`RecordSkip`, `RecordExtraMeal`, `CancelSkip`, `CancelExtraMeal`, `BulkRecordSkip`, `CalculateExpectedMeals`).
- `tests/Feature/HardeningTest.php` - Central hardening test suite (12 tests currently).
- `app/Console/Commands/Phase1milstones.md` - Master Roadmap document.
