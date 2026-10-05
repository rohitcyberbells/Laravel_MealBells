# MealBells — Complete Screen Flows & Architectural Navigation Guide

This document is the authoritative reference for all user screen flows, navigation paths, role permissions, data resolution rules, and status tracking in **MealBells**.

---

## 🗺️ 1. Global Navigation Architecture Map

```
                             ┌─────────────────────────┐
                             │     Welcome / Home      │
                             │           (/)           │
                             └────────────┬────────────┘
                                          │
                        Is User Already Authenticated?
                                   ├─── YES ───► Redirect to Role Dashboard
                                   └─── NO  ───► Render Marketing / Welcome Page
                                          │
                                          ▼
                             ┌─────────────────────────┐
                             │       Login Page        │
                             │        (/login)         │
                             └────────────┬────────────┘
                                          │
                                 Auth & Role Verify
                                          │
   ┌───────────────────────┬──────────────┴──────────────┬─────────────────────────┐
   ▼                       ▼                             ▼                         ▼
┌──────────────────┐ ┌─────────────────────────┐ ┌─────────────────────┐ ┌────────────────────────┐
│ Super Admin Dash │ │ Tiffin Admin Dashboard  │ │ Company Admin Dash  │ │ Employee Dashboard     │
│ (/super-admin/db)│ │ (/tiffin-admin/db)      │ │ (/company-admin/db) │ │ (/employee/dashboard)  │
└────────┬─────────┘ └──────────┬──────────────┘ └──────────┬──────────┘ └───────────┬────────────┘
         │                      │                           │                        │
 • Company Pairing      • Mon-Fri Weekly Menu       • Daily Meal Count       • Today's Meal Status
 • 🩺 System Health     • 🚨 Today Override         • 📈 Adoption Report     • 1-Tap Self Skip
 (super-admin/health)   • 📊 Vendor Prep View       • 📅 Calendar Overrides  • Recurring Skip Rules
```

---

## 🟢 2. Current Screen Flows (Built)

### Flow 1: Landing Page & Smart Auth Redirect
- **Status**: **Built**
- **URL**: `/`
- **Controller**: Closure in `routes/web.php`
- **View**: `resources/js/Pages/Welcome.vue`

---

### Flow 2: Authentication & Role-Based Routing
- **Status**: **Built**
- **URL**: `/login` (GET / POST)
- **Controller**: `App\Http\Controllers\Auth\LoginController.php`
- **View**: `resources/js/Pages/Auth/Login.vue`
- **Supports**: Standard email login AND Employee company code + login code login. Updates `last_login_at`.
- **Role Redirection**:
  - `super_admin` ➔ `/super-admin/dashboard`
  - `tiffin_admin` ➔ `/tiffin-admin/dashboard`
  - `company_admin` ➔ `/company-admin/dashboard`
  - `employee` ➔ `/employee/dashboard`

---

### Flow 3: Super Admin Screen Flow & System Health
- **Status**: **Built**
- **URLs**: `/super-admin/dashboard`, `/super-admin/health`
- **Controller**: `SuperAdminController.php` & `HealthController.php`
- **Views**: `SuperAdmin/Dashboard.vue`, `SuperAdmin/Health.vue`
- **Capabilities**: Company pairing, user password resets, real-time system health dashboard (scheduler heartbeat, failed jobs, 24h locked snapshots, missing snapshots table).

---

### Flow 4: Tiffin Admin Screen Flow & Vendor Prep View
- **Status**: **Built**
- **URLs**: `/tiffin-admin/dashboard`, `/tiffin-admin/preparation`
- **Controllers**: `TiffinAdminController.php` & `VendorPreparationController.php`
- **Views**: `TiffinAdmin/Dashboard.vue`, `TiffinAdmin/Preparation.vue`

---

### Flow 5: Company Admin Screen Flow & Adoption Report
- **Status**: **Built**
- **URLs**: `/company-admin/dashboard`, `/company-admin/employees`, `/company-admin/calendar`, `/company-admin/reports/adoption`
- **Capabilities**: Daily count overview, CSV employee/skip imports, calendar holiday overrides, adoption metrics report (7/30 days toggle, self-service %, channel breakdown, logins summary).

---

### Flow 6: Employee Dashboard Screen Flow
- **Status**: **Built**
- **URL**: `/employee/dashboard`
- **Controller**: `EmployeeDashboardController.php`
- **View**: `Employee/Dashboard.vue`
- **Capabilities**: View today & next 7 days meals, 1-tap self-skip, date range skip, self & recurring skip cancellation, recurring skip rule management.

---

## ⏳ 3. Planned / Deferred Features (Post-MVP)

- **One-tap Email Skip Link (`/skip/{token}`)**: Deferred (MVP relies on employee login portal).
- **Daily Meal Reminders**: Deferred.
- **Public Skip Tokens & Unsubscribe**: Deferred.
- ** Cafeteria Kiosk Display (`/display`)**: Deferred.
- **Mobile Sanctum API (`/api/v1`)**: Deferred.
