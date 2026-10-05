# MealBells: Milestones Guide (1g se B7)

## Master Milestone Status Overview

Status: Stage 0, Stage 1 (1a-1j), B1 (1k), B2 (1l), B3/B4, B5, B6, and B7 (Adoption, Health, Production, Hardening) **ALL COMPLETED**!

**Full Test Suite**: **133 Tests Passed Green** (`vendor/bin/phpunit`).

### Completed Milestones Summary
- **1a-1f**: Backend Core (CalculateExpectedMeals, Lock, Cutoff, Scheduler) - **DONE**
- **1g**: Vendor Preparation View (BuildVendorPreparationView, Controller, Vue UI) - **DONE**
- **1h**: Cancel Actions + Bulk Skip (CancelSkip, CancelExtraMeal, BulkRecordSkip) - **DONE**
- **1i**: Company Admin UI (CompanyAdminController, EmployeeController, SkipController, MealAdjustmentController, CompanySettingController) - **DONE**
- **1j Part 1-3**: Centralized Architecture, Guards Order, Outcomes, PostCutoffChange Hardening - **DONE**
- **B1 (1k)**: Leave / WFH CSV (`ValidateSkipCsv`, `ImportSkips`) - **DONE**
- **B2 (1l)**: Summary, Anomaly & Review Engine (`PrepareDailyCountSummary`, `DetectCountAnomalies`, `ConfirmDailyCount`) - **DONE**
- **B3 & B4**: Employee Logins & Employee Portal (`CreateEmployeeLogins`, `ResetEmployeePassword`, Employee Login Flow) - **DONE**
- **B5**: Recurring Skips & Company Calendar (`CreateRecurringSkip`, `SetCalendarDay`, generator command) - **DONE**
- **B6**: Employee Meal Flow Verification, Self-Skip & Cancel Rule Verification - **DONE**
- **B7 (1m & 2g/2h)**: Adoption Metrics, Health Dashboard, Single Active Assignment DB Constraint, Notifications Queueing, Hardening & Privacy Sweep - **DONE**

### Deferred / Planned Features (Post-MVP)
- One-tap email skip link (`/skip/{token}`) - *Planned*
- Daily evening meal reminders - *Planned*
- Public skip tokens & Unsubscribe flow - *Planned*
- Attendance/HRMS webhook integration (`POST /api/v1/attendance`) - *Planned*

---

## Technical Architectural Summary (B7)
1. **Adoption Metrics (`BuildAdoptionReport`)**: Aggregate channel breakdown (`hr`, `leave`, `wfh`, `self`, `link`, `recurring`), self-service percentage, active employee portal login statistics, daily series, and HR burden alert. Guaranteed employee privacy (no names/emails/reasons).
2. **System Health (`GET super-admin/health`)**: Tracks scheduler heartbeat timestamp from Cache, failed jobs count, 24h locked snapshots, and passed cutoff missing snapshots.
3. **DB Level Constraint**: `company_tiffin_assignments` enforces single active assignment per company via virtual generated column + unique index.
4. **Queue & Notifications**: All domain notifications implement `ShouldQueue`. `ProcessDailyCutoff` uses company-level isolation and notifies Super Admins once per company-date on processing errors.
5. **Hardening**: `must_change_password` enforced globally. Inactive employees have login blocked and future recurring skips paused/cancelled. Cross-company access rejected with 403.
