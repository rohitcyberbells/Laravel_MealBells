# MealBells — Complete Screen Flows & Architectural Navigation Guide

> **Rule**: Har milestone khatam hone par is doc mein uski screen flow update hogi.

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
         ┌────────────────────────────────┼────────────────────────────────┐
         ▼                                ▼                                ▼
┌─────────────────────────┐    ┌─────────────────────────┐    ┌─────────────────────────┐
│  Super Admin Dashboard  │    │  Tiffin Admin Dashboard │    │ Company Admin Dashboard │
│  (/super-admin/db)      │    │  (/tiffin-admin/db)     │    │ (/company-admin/db)     │
└────────┬────────────────┘    └──────────┬──────────────┘    └──────────┬──────────────┘
         │                                │                              │
 • Add Companies                  • Build Mon-Fri Menu           • View Today's Meal
 • Add Tiffin Services            • Save Draft / Publish         • 🚨 Daily Override Alert
 • 1-Click 1:1 Pairing            • 🚨 Post Meal Override        • View Mon-Fri Menu Grid
                                  • 📊 Vendor Prep View (1g)     • 🔔 Alert Feed (Built)
```

---

## 🟢 2. Current Screen Flows (Built vs Planned)

### Flow 1: Landing Page & Smart Auth Redirect
- **Status**: **Built**
- **URL**: `/`
- **Controller**: Closure in `routes/web.php`
- **View**: `resources/js/Pages/Welcome.vue`
- **Behavior**:
  - If **Guest**: Renders `Welcome.vue` marketing page.
  - If **Authenticated**: Redirects user straight to their role dashboard (`super-admin.dashboard`, `tiffin-admin.dashboard`, or `company-admin.dashboard`).

---

### Flow 2: Authentication & Role-Based Routing
- **Status**: **Built**
- **URL**: `/login` (GET / POST)
- **Controller**: [LoginController.php](file:///Users/imac/Desktop/MEALBELLS/app/Http/Controllers/Auth/LoginController.php)
- **View**: `resources/js/Pages/Auth/Login.vue`
- **Security**: Rate limited (`throttle:5,1`) on POST requests.
- **Role Redirection Matrix**:
  - `super_admin` ➔ `/super-admin/dashboard`
  - `tiffin_admin` ➔ `/tiffin-admin/dashboard`
  - `company_admin` ➔ `/company-admin/dashboard`
- **Logout**: `POST /logout` invalidates session and redirects to `/login`.

---

### Flow 3: Super Admin Screen Flow
- **Status**: **Built**
- **URL**: `/super-admin/dashboard`
- **Middleware**: `auth`, `role:super_admin`
- **Controller**: [SuperAdminController.php](file:///Users/imac/Desktop/MEALBELLS/app/Http/Controllers/SuperAdmin/SuperAdminController.php)
- **View**: `resources/js/Pages/SuperAdmin/Dashboard.vue`
- **Capabilities**:
  1. **Companies Tab**: View registered companies. Form to create a new Company + auto-generate a Company Admin user.
  2. **Tiffin Services Tab**: View kitchen services. Form to create a new Tiffin Service + auto-generate a Tiffin Admin user.
  3. **1-Click Pairing Tool**: Select 1 Company + 1 Tiffin Service ➔ Deactivates previous pairings and creates active `CompanyTiffinAssignment`.
  4. **Pairing History Tab**: View active vs historical company-tiffin pairings.

---

### Flow 4: Tiffin Admin Screen Flow
- **Status**: **Built**
- **URL**: `/tiffin-admin/dashboard`
- **Middleware**: `auth`, `role:tiffin_admin`
- **Controller**: [TiffinAdminController.php](file:///Users/imac/Desktop/MEALBELLS/app/Http/Controllers/TiffinAdmin/TiffinAdminController.php)
- **View**: `resources/js/Pages/TiffinAdmin/Dashboard.vue`
- **Capabilities**:
  1. **Assigned Company Header**: Shows active paired company name or "Not Assigned Yet" badge.
  2. **📅 Monday – Friday Weekly Menu Builder**:
     - Form inputs for Monday through Friday meal descriptions.
     - Action 1: **"Save Draft"** ➔ Saves `WeeklyMenu` with `status = 'draft'`. (Company Admin cannot see drafts).
     - Action 2: **"Publish Menu"** ➔ Saves `WeeklyMenu` with `status = 'published'`. (Instantly visible to Company Admin).
  3. **🚨 Emergency Today's Meal Override Card**:
     - Form to enter today's actual meal + optional reason.
     - Upserts `DailyOverrides` for `date = Carbon::today()`.

---

### Flow 5: Company Admin Screen Flow
- **Status**: **Built**
- **URL**: `/company-admin/dashboard`
- **Middleware**: `auth`, `role:company_admin`
- **Controller**: [CompanyAdminController.php](file:///Users/imac/Desktop/MEALBELLS/app/Http/Controllers/CompanyAdmin/CompanyAdminController.php)
- **View**: `resources/js/Pages/CompanyAdmin/Dashboard.vue`
- **Capabilities**:
  1. **Assigned Kitchen Header**: Displays assigned Tiffin Service name & kitchen contact phone.
  2. **Unassigned State Banner**: If unassigned, shows notice: *"Account Pending Pairing"*.
  3. **🍱 Today's Featured Meal Card**:
     - Resolves priority (DailyOverride > Published Weekly Menu).
     - Renders **🚨 Daily Kitchen Override Active** alert badge + override description + kitchen reason note when active.
  4. **📅 Published Weekly Menu Grid**: Displays Monday through Friday published menu grid.

---

### Flow 6: Notification Alert Feed
- **Status**: **Built**
- **Location**: `CompanyAdmin/Dashboard.vue`
- **Capabilities**:
  - Fetches recent notification records from `notifications` database table via `CompanyAdminController@index`.
  - Displays alert banner feed showing menu publishing and daily override alerts with timestamps and kitchen reason notes.

---

### Flow 7: Vendor Preparation View (Milestone 1g)
- **Status**: **Backend Built & Action Tested** (Full UI Navigation Integration: **Planned**)
- **URL**: `/tiffin-admin/preparation`
- **Middleware**: `auth`, `role:tiffin_admin`
- **Controller**: [VendorPreparationController.php](file:///Users/imac/Desktop/MEALBELLS/app/Http/Controllers/TiffinAdmin/VendorPreparationController.php)
- **View**: `resources/js/Pages/TiffinAdmin/Preparation.vue`
- **Action**: [BuildVendorPreparationView.php](file:///Users/imac/Desktop/MEALBELLS/app/Actions/Meal/BuildVendorPreparationView.php)
- **Privacy & Safety Rules**:
  - Absolutely NO employee names, codes, emails, or individual skip reasons exposed to vendors.
  - Sources locked count from `meal_counts` snapshot + `meal_count_changes`.
  - Sources unlocked count from live `CalculateExpectedMeals` with `is_estimate = true`.

---

### Flow 8: Week Selector Navigation (Setup M8)
- **Status**: **Planned**
- **Component**: Navigation buttons `[← Previous Week] [Current Week] [Next Week →]` on Tiffin & Company Admin dashboards.
- **Capability**: Tiffin Admins can draft/publish menus 1–2 weeks in advance.

---

### Flow 9: Public Office Cafeteria Kiosk Display
- **Status**: **Planned**
- **URL**: `/display` or `/today`
- **Capability**: Wall-mounted cafeteria TV / iPad auto-refreshing view showing Today's meal & live override alert pulse.

---

### Flow 10: Mobile App Sanctum API Layer (`/api/v1`)
- **Status**: **Planned**
- **Endpoints**: `POST /api/v1/auth/login`, `GET /api/v1/menu/today`, `GET /api/v1/menu/weekly`.
- **Architecture**: Leverages shared domain action classes in `app/Actions/`.

---

## ⚙️ 3. Stage 1 Engine & Safety Core (Backend Built, UI Planned 1i)

| Component / Action | Type | Status | Description |
| :--- | :--- | :--- | :--- |
| **`MealCalendar`** | Service | **Built (1f)** | `MealCalendar::isMealDay(Company $company, string\|Carbon $date): bool`. Evaluates `company_settings.meal_days` (JSON, default Mon-Fri `[1,2,3,4,5]`). |
| **`ValidateEmployeeCsv` & `ImportEmployeeCsv`** | Actions | **Built (1a)** (UI: **Planned 1i**) | 2-phase CSV import pipeline (preview validation & database upsert). |
| **`RecordSkip`** | Action | **Built (1b/1f)** (UI: **Planned 1i**) | Logs employee skip exception with cutoff guard and locked count check. |
| **`RecordExtraMeal`** | Action | **Built (1b/1f)** (UI: **Planned 1i**) | Logs guest/visitor extra meals with positive quantity guard & cutoff check. |
| **`CalculateExpectedMeals`** | Action | **Built (1c/1f)** (UI: **Planned 1i**) | Pure demand engine: $\text{Expected} = \text{Base} + \text{Extra} - \text{Skips}$. Returns source breakdown & `is_meal_day` flag. |
| **`ConfirmDailyCount`** | Action | **Built (1d/1f)** | Generates immutable daily snapshot in `meal_counts` table and locks `locked_at`. Dispatches `DailyCountConfirmed` event. |
| **`mealbells:process-cutoff`** | Command | **Built (1d/1f)** | Scheduled Artisan cron job in `routes/console.php` (`everyMinute()`, `withoutOverlapping()`, `onOneServer()`). Timezone-aware & idempotent. |
| **`RecordPostCutoffChange`** | Action | **Built (1e)** | Logs post-cutoff late adjustments ($+5$ / $-3$) into `meal_count_changes` audit table without altering original snapshot. |

---

## 🧠 4. Today's Meal Priority Resolution Matrix

When Company Admin or Mobile API requests "Today's Meal", the system follows this strict resolution chain:

```
                         Does a DailyOverride exist 
                             for date = today()?
                                     │
                 ┌───────────────────┴───────────────────┐
                 ▼                                       ▼
               YES                                       NO
                 │                                       │
      Show Today's Override           Does a Published WeeklyMenu exist
     (with 🚨 Override Badge           for current week_start_date?
     + Kitchen Reason Note)                              │
                                         ┌───────────────┴───────────────┐
                                         ▼                               ▼
                                       YES                               NO
                                         │                               │
                              Does an item exist for            Show "No Menu Published"
                              today's day_of_week?                       │
                                         │                               ▼
                         ┌───────────────┴───────────────┐              null
                         ▼                               ▼
                       YES                               NO
                         │                               │
               Show Planned Weekly Meal                 null
```

---

## 🚨 5. Known Gaps & Current Limitations

The following items are identified architectural limitations in the current implementation to be addressed in subsequent milestones:

1. **Mon-Fri Menu Hardcoding**: The Weekly Menu builder UI grid is currently fixed to Monday–Friday (`daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']`). Saturday/Sunday menu items require grid extension.
2. **Company Timezone Resolution in Controller**: Today's meal resolution in `CompanyAdminController@index` currently uses system time `Carbon::today()` instead of `Carbon::today($company->setting->timezone)`.
3. **Single Date Daily Override**: `DailyOverrides` model and controller action currently target strictly `Carbon::today()`. Advance override scheduling for future dates is not supported yet.
4. **Single Company Header in Tiffin Dashboard**: `TiffinAdminController@index` fetches only the first active company assignment (`CompanyTiffinAssignment::where('is_active', true)->first()`), which does not reflect multi-company assignments in the header widget.
5. **No Backup Admin Creation Screen**: `company_settings` supports `backup_admin_id`, but there is currently no Super Admin or Company Admin UI form to generate or select a secondary backup admin user.
