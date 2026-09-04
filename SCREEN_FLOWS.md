# MealBells — Complete Screen Flows & Architectural Navigation Guide

This document is the authoritative reference for all user screen flows, navigation paths, role permissions, and data resolution rules in **MealBells**. Refer to this guide to understand the system flow and architectural conventions.

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
```

---

## 🟢 2. Current Screen Flows (Active & Built in Phase 1 MVP)

### Flow 1: Landing Page & Smart Auth Redirect
- **URL**: `/`
- **Controller**: Closure in `routes/web.php`
- **View**: `resources/js/Pages/Welcome.vue`
- **Behavior**:
  - If **Guest**: Renders `Welcome.vue` marketing page.
  - If **Authenticated**: Bounces user straight to their role dashboard (`super-admin.dashboard`, `tiffin-admin.dashboard`, or `company-admin.dashboard`).

---

### Flow 2: Authentication & Role-Based Routing
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
- **URL**: `/super-admin/dashboard`
- **Middleware**: `auth`, `role:super_admin`
- **Controller**: [SuperAdminController.php](file:///Users/imac/Desktop/MEALBELLS/app/Http/Controllers/SuperAdmin/SuperAdminController.php)
- **View**: `resources/js/Pages/SuperAdmin/Dashboard.vue`
- **Key Capabilities**:
  1. **Companies Tab**: View list of registered companies. Form to create a new Company + auto-generate a Company Admin user.
  2. **Tiffin Services Tab**: View list of kitchen services. Form to create a new Tiffin Service + auto-generate a Tiffin Admin user.
  3. **1-Click Pairing Tool**: Select 1 Company + 1 Tiffin Service ➔ Deactivates previous pairings for the company and creates an active `CompanyTiffinAssignment`.
  4. **Pairing History Tab**: View active vs historical company-tiffin pairings.

---

### Flow 4: Tiffin Admin Screen Flow
- **URL**: `/tiffin-admin/dashboard`
- **Middleware**: `auth`, `role:tiffin_admin`
- **Controller**: [TiffinAdminController.php](file:///Users/imac/Desktop/MEALBELLS/app/Http/Controllers/TiffinAdmin/TiffinAdminController.php)
- **View**: `resources/js/Pages/TiffinAdmin/Dashboard.vue`
- **Key Capabilities**:
  1. **Assigned Company Header**: Shows active paired company (`TechCorp Solutions`), or "Not Assigned Yet" badge.
  2. **📅 Monday – Friday Weekly Menu Builder**:
     - Form inputs for Monday through Friday meal descriptions.
     - Action 1: **"Save Draft"** ➔ Saves `WeeklyMenu` with `status = 'draft'`. (Company Admin cannot see drafts).
     - Action 2: **"Publish Menu"** ➔ Saves `WeeklyMenu` with `status = 'published'`. (Instantly visible to Company Admin).
  3. **🚨 Emergency Today's Meal Override Card**:
     - Form to enter today's actual meal + optional reason (e.g. *"Ran out of Paneer, swapping to Chana Masala"*).
     - Upserts `DailyOverrides` strictly for `date = Carbon::today()`.

---

### Flow 5: Company Admin Screen Flow
- **URL**: `/company-admin/dashboard`
- **Middleware**: `auth`, `role:company_admin`
- **Controller**: [CompanyAdminController.php](file:///Users/imac/Desktop/MEALBELLS/app/Http/Controllers/CompanyAdmin/CompanyAdminController.php)
- **View**: `resources/js/Pages/CompanyAdmin/Dashboard.vue`
- **Key Capabilities**:
  1. **Assigned Kitchen Header**: Displays assigned Tiffin Service name & kitchen contact phone.
  2. **Unassigned State Banner**: If Super Admin hasn't paired a tiffin service yet, shows a friendly notice: *"Account Pending Pairing"*.
  3. **🍱 Today's Featured Meal Card**:
     - Evaluates Today's Meal Resolution Priority (see section below).
     - If Kitchen Override is active for today ➔ Renders **🚨 Daily Kitchen Override Active** alert badge + override description + kitchen reason note.
  4. **📅 Published Weekly Menu Grid**:
     - Displays Monday through Friday meal grid.
     - Enforces `status = 'published'` rule (drafts are hidden).

---

## 🧠 3. Today's Meal Priority Resolution Matrix

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

## 🚀 4. Future Screen Flows (Planned & Architecture-Ready)

### Flow 6: In-App Notification Bell & Drawer (Milestone 7)
- **Component**: Header dropdown bell icon on Company Admin dashboard.
- **Trigger**: When Tiffin Admin clicks **Publish Menu** or **Post Today's Override**, a database notification is stored in `notifications` table.
- **UI**: Unread badge counter (`🔔 2`). Clicking bell opens a drawer showing recent menu change logs.

---

### Flow 7: Week Selector Navigation (Milestone 8)
- **Component**: Navigation buttons `[← Previous Week] [Current Week] [Next Week →]` on Tiffin & Company Admin dashboards.
- **Capability**: Tiffin Admins can draft and publish menus 1–2 weeks in advance. Company Admins can inspect next week's menu.

---

### Flow 8: Public Office Cafeteria TV / Kiosk Display
- **URL**: `/display` or `/today` (Public or Token-authenticated)
- **Target**: Wall-mounted TV / iPad in the company cafeteria.
- **UI**: High-contrast, large-typography auto-refreshing kiosk view showing:
  - Big bold dish name for Today
  - Kitchen name & phone number
  - Live animated pulse if an override was posted today

---

### Flow 9: Mobile App Sanctum API Layer (`/api/v1`)
- **Endpoints**:
  - `POST /api/v1/auth/login` ➔ Returns Sanctum Bearer Token.
  - `GET /api/v1/menu/today` ➔ Returns JSON of Today's Meal (using same resolution priority).
  - `GET /api/v1/menu/weekly` ➔ Returns JSON of Published Weekly Menu.
- **Design Principle**: Web (Inertia) and Mobile (API) share the exact same Service/Action logic layer in `app/Services/`.
