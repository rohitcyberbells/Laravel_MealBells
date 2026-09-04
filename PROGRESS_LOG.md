# MealBells — Development & Scaffolding Log

This document records all architectural decisions, setup steps, database migrations, and milestones completed during the development of **MealBells**.

---

## 📅 Milestone 1: Full-Stack Infrastructure & Scaffolding (Completed)

### 1. Framework & Core Stack
- **Framework**: Laravel 12
- **Frontend Architecture**: Inertia.js (v3) + Vue 3 + TypeScript support
- **Styling**: Tailwind CSS (v4) with `@tailwindcss/vite` plugin
- **Asset Bundler**: Vite with `@vitejs/plugin-vue`
- **Database**: SQLite for local development (`database/database.sqlite`)

### 2. Environment & Boilerplate Configuration
- Created Inertia root template at [resources/views/app.blade.php](file:///Users/imac/Desktop/MEALBELLS/resources/views/app.blade.php) with `@inertia` directive and `@inertiaHead`.
- Configured Vue 3 mount point in [resources/js/app.js](file:///Users/imac/Desktop/MEALBELLS/resources/js/app.js) using `createInertiaApp` and `resolvePageComponent`.
- Configured [vite.config.js](file:///Users/imac/Desktop/MEALBELLS/vite.config.js) with `@vitejs/plugin-vue` and `@tailwindcss/vite`.
- Registered Inertia middleware `\App\Http\Middleware\HandleInertiaRequests::class` inside [bootstrap/app.php](file:///Users/imac/Desktop/MEALBELLS/bootstrap/app.php).
- Verified live rendering via smoke test component [resources/js/Pages/Welcome.vue](file:///Users/imac/Desktop/MEALBELLS/resources/js/Pages/Welcome.vue) at `http://127.0.0.1:8000`.

### 3. Directory Structure Initialized
- Created role-separated view directories under `resources/js/Pages/`:
  - `resources/js/Pages/SuperAdmin/`
  - `resources/js/Pages/TiffinAdmin/`
  - `resources/js/Pages/CompanyAdmin/`
  - `resources/js/Pages/Auth/`

---

## 🗄 Milestone 2: Database Schema & Models (Completed)

All database migrations have been executed successfully against SQLite.

| Model / Table | File Paths | Key Fields / Details |
|---|---|---|
| **Company** | [Company.php](file:///Users/imac/Desktop/MEALBELLS/app/Models/Company.php) <br> `database/migrations/..._create_companies_table.php` | `id`, `name`, `address`, `contact_phone`, `timestamps`. Relationships: `assignments()`, `activeAssignment()`. |
| **TiffinService** | [TiffinService.php](file:///Users/imac/Desktop/MEALBELLS/app/Models/TiffinService.php) <br> `database/migrations/..._create_tiffin_services_table.php` | `id`, `name`, `address`, `contact_phone`, `timestamps`. Relationship: `assignments()`. |
| **User (Extended)** | [User.php](file:///Users/imac/Desktop/MEALBELLS/app/Models/User.php) <br> `database/migrations/..._add_role_and_relation_to_users_table.php` | Added `role` (`super_admin`, `company_admin`, `tiffin_admin`), `company_id` (FK), `tiffin_service_id` (FK). Relationships: `company()`, `tiffinService()`. Preserved `HasFactory`, `Notifiable`. |
| **CompanyTiffinAssignment** | [CompanyTiffinAssignment.php](file:///Users/imac/Desktop/MEALBELLS/app/Models/CompanyTiffinAssignment.php) <br> `database/migrations/..._create_company_tiffin_assignments_table.php` | Tracks 1-to-1 pairings over time: `company_id` (FK), `tiffin_service_id` (FK), `is_active` (bool), `assigned_at`, `unassigned_at`. |
| **WeeklyMenu** | [WeeklyMenu.php](file:///Users/imac/Desktop/MEALBELLS/app/Models/WeeklyMenu.php) <br> `database/migrations/..._create_weekly_menus_table.php` | `tiffin_service_id` (FK), `week_start_date`, `status` (`draft` / `published`). Relationships: `tiffinService()`, `items()`. |
| **MenuItem** | [MenuItem.php](file:///Users/imac/Desktop/MEALBELLS/app/Models/MenuItem.php) <br> `database/migrations/..._create_menu_items_table.php` | `weekly_menu_id` (FK), `day_of_week` (Monday - Sunday), `meal_description`. Relationship: `weeklyMenu()`. |
| **DailyOverrides** | [DailyOverrides.php](file:///Users/imac/Desktop/MEALBELLS/app/Models/DailyOverrides.php) <br> `database/migrations/..._create_daily_overrides_table.php` | Overrides today's planned meal: `tiffin_service_id` (FK), `date`, `meal_description`, `reason`. Explicitly bound to `$table = 'daily_overrides'`. |
| **Notifications** | `database/migrations/..._create_notifications_table.php` | Built-in Laravel notification queue table. |

---

## 🔒 Milestone 3: Manual Session Authentication & Role Routing (Completed)

- **Database Seeder**: Seeded demo accounts for all 3 roles (`admin@mealbells.com`, `tiffin@mealbells.com`, `company@mealbells.com`) inside [DatabaseSeeder.php](file:///Users/imac/Desktop/MEALBELLS/database/seeders/DatabaseSeeder.php).
- **Login Controller**: Created [LoginController.php](file:///Users/imac/Desktop/MEALBELLS/app/Http/Controllers/Auth/LoginController.php) handling login creation, credential verification, role-based redirects, and session destruction.
- **Rate Limiting**: Configured `throttle:5,1` rate limiting middleware on login POST requests.
- **Role Security Middleware**: Implemented [EnsureUserRole.php](file:///Users/imac/Desktop/MEALBELLS/app/Http/Middleware/EnsureUserRole.php) protecting routes per role (`super_admin`, `tiffin_admin`, `company_admin`).
- **Vue Login Interface**: Created [resources/js/Pages/Auth/Login.vue](file:///Users/imac/Desktop/MEALBELLS/resources/js/Pages/Auth/Login.vue) with quick 1-click demo login buttons.
- **Smart Auth Home Redirect**: Added `Auth::check()` to `/` route in [routes/web.php](file:///Users/imac/Desktop/MEALBELLS/routes/web.php) automatically redirecting logged-in users straight to their dashboard!
- **Live Verification**: Automated browser verification confirmed login flow and redirect to `/super-admin/dashboard`!

---

## 👑 Milestone 4: Super Admin Management Dashboard (Completed)

- **Super Admin Controller**: Built [SuperAdminController.php](file:///Users/imac/Desktop/MEALBELLS/app/Http/Controllers/SuperAdmin/SuperAdminController.php).
- **Super Admin Dashboard UI**: Built [resources/js/Pages/SuperAdmin/Dashboard.vue](file:///Users/imac/Desktop/MEALBELLS/resources/js/Pages/SuperAdmin/Dashboard.vue) with 1-click pairing tool.

---

## 🍱 Milestone 5: Tiffin Admin Features (Completed)

- **Tiffin Admin Controller**: Created [TiffinAdminController.php](file:///Users/imac/Desktop/MEALBELLS/app/Http/Controllers/TiffinAdmin/TiffinAdminController.php) managing Monday - Friday weekly menu building (`saveMenu`) and daily kitchen meal overrides (`storeOverride`).
- **Tiffin Admin UI**: Created [resources/js/Pages/TiffinAdmin/Dashboard.vue](file:///Users/imac/Desktop/MEALBELLS/resources/js/Pages/TiffinAdmin/Dashboard.vue) with Monday – Friday meal schedule inputs, draft vs published state controls, and emergency override banner.

---

## 🏢 Milestone 6: Company Admin Portal (Completed)

- **Company Admin Controller**: Created [CompanyAdminController.php](file:///Users/imac/Desktop/MEALBELLS/app/Http/Controllers/CompanyAdmin/CompanyAdminController.php) fetching published weekly menus and today's meal override status.
- **Company Admin UI**: Created [resources/js/Pages/CompanyAdmin/Dashboard.vue](file:///Users/imac/Desktop/MEALBELLS/resources/js/Pages/CompanyAdmin/Dashboard.vue) displaying assigned Tiffin Service details, today's meal highlight with override badge, Monday - Friday weekly menu grid, and unassigned state banner.

---

## 🎯 Next Steps (In Progress)

- [ ] In-App Change Notification system when Tiffin Admin updates menu or posts an override.
- [ ] Centralized Service / Action classes for shared web + API logic.
- [ ] `/api/v1` Sanctum API endpoints for mobile readiness.
