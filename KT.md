# MealBells — Knowledge Transfer Document

**Purpose of this doc:** Bring anyone new up to speed on what MealBells is, what we're building in Phase 1 (MVP), the tech decisions made and why, and the current status.

---

## 1. What is MealBells?

MealBells is a **B2B communication tool** connecting **one company** with **one assigned tiffin/catering service** that feeds its employees.

It is **not**:
- ❌ A food delivery app (no ordering, no payment, no delivery tracking)
- ❌ A marketplace with multiple vendor options
- ❌ An employee-facing app (in MVP) — it's used by office admins and kitchen admins, not individual employees

**The problem it solves:** Today, tiffin menus and day-to-day changes are usually shared via WhatsApp or word of mouth — messy, easy to miss, no single source of truth. MealBells replaces that with one dashboard where:
- The tiffin service publishes/updates the menu
- The company sees the current menu clearly, and gets notified when something changes

### Example flow
- Monday: Tiffin Admin publishes the week's menu (Mon–Sun).
- Company Admin logs in, sees the full week planned.
- Wednesday: Kitchen runs out of an ingredient, swaps the planned meal. Tiffin Admin updates "today's meal."
- Company Admin gets notified instantly instead of finding out after the fact.

---

## 2. User Roles

| Role | Can do |
|---|---|
| **Super Admin** | Creates Company & Tiffin Service accounts, assigns one Company to one Tiffin Service, manages the platform |
| **Tiffin Admin** | Publishes/edits the weekly menu, overrides today's meal if it changes, sees which company they're assigned to |
| **Company Admin** | Views the weekly menu & today's meal, sees their assigned tiffin service, receives notifications on changes |

Each user has **exactly one role** and belongs to at most **one** company or tiffin service (no multi-role users in MVP — kept simple on purpose).

---

## 3. MVP Scope (Phase 1) — what we ARE building now

1. Auth (manual, not Laravel Breeze) — login for all 3 roles
2. Super Admin: create Company, create Tiffin Service, assign Company ↔ Tiffin Service
3. Tiffin Admin: publish weekly menu (draft → published), override today's meal
4. Company Admin: view weekly menu, view today's meal
5. In-app notifications when a menu changes
6. Astro landing/marketing page (separate, static)

### Explicitly NOT in MVP (future phases)
- Employee attendance, meal confirmations, holidays *(Phase 2)*
- Meal preferences, analytics, reports, delivery status *(Phase 3)*
- Billing, HR integrations, mobile app UI, multi-kitchen support *(Phase 4)*

> Note: even though the **mobile app itself** is a later phase, we are building the **API layer now** (see stack section) so we don't have to redo backend work when mobile development starts.

---

## 4. Tech Stack — Final Decision

| Layer | Choice |
|---|---|
| Backend | Laravel 12 |
| Web frontend | Vue 3 + TypeScript + Inertia.js + Tailwind CSS |
| Mobile app (later phase) | Flutter |
| API (for mobile) | Laravel Sanctum, `/api/v1/...` — being built now, alongside Inertia |
| Landing page | Astro (fully separate, static) |
| Database (local dev) | SQLite |
| Database (production) | MySQL |
| Auth (web) | Manual Laravel session auth — **no Breeze scaffolding** |
| Auth (API) | Sanctum tokens |

### Key architectural decision: Inertia AND a separate API, side by side
- **Web app** uses Inertia — Laravel controllers return Vue pages directly, no JSON API involved.
- **Mobile app** will use a separate `/api/v1/...` JSON API (Sanctum-secured).
- This means some business logic (e.g. "get weekly menu", "publish menu") will technically be reachable two ways — once via Inertia controller, once via API controller. To avoid duplicating actual logic, we'll centralize business logic in **Service/Action classes** that both controller types call into. Only the thin "how do I return this" layer differs.

### Why SQLite → MySQL
- SQLite locally = zero setup, no DB server needed, fast to start building
- MySQL in production = built for concurrent access, mature tooling, matches original plan
- Our schema uses only standard, portable column types (string, text, boolean, date, bigint, foreign keys) — no MySQL-only or SQLite-only features — so switching later is low-risk. Migrations just get re-run against MySQL; only local test data doesn't carry over (not a concern since it's just dev data).

### Why Flutter for mobile (not React Native)
- Single codebase, strong performance, good long-term choice — decided since mobile isn't urgent (later phase), so upfront language difference (Dart vs JS) is an acceptable tradeoff for a cleaner mobile experience.

---

## 5. Database Schema (Phase 1)

### `users`
Covers all 3 roles in one table.
- `id`, `name`, `email`, `password`
- `role` — enum: `super_admin` / `company_admin` / `tiffin_admin`
- `company_id` — nullable FK → companies (set only if company_admin)
- `tiffin_service_id` — nullable FK → tiffin_services (set only if tiffin_admin)

### `companies`
- `id`, `name`, `address`, `contact_phone`

### `tiffin_services`
- `id`, `name`, `address`, `contact_phone`

### `company_tiffin_assignments`
Separate table (not just a FK on companies) so we **preserve history** if a company is reassigned to a different tiffin service later.
- `id`, `company_id` (FK), `tiffin_service_id` (FK)
- `is_active` — only one active assignment per company at a time
- `assigned_at`, `unassigned_at` (nullable)

> A company can exist with **zero active assignment** temporarily (e.g. right after Super Admin creates it, before pairing with a tiffin service). Company Admin dashboard shows a friendly "not yet assigned" message in that case.

### `weekly_menus`
One row per week, per tiffin service.
- `id`, `tiffin_service_id` (FK), `week_start_date`
- `status` — enum: `draft` / `published` (Company Admin only ever sees `published`)

### `menu_items`
- `id`, `weekly_menu_id` (FK), `day_of_week` (enum: monday–sunday), `meal_description`

### `daily_overrides`
Today's actual meal, if different from what was planned.
- `id`, `tiffin_service_id` (FK), `date`, `meal_description`, `reason` (nullable)

> Logic: when checking "today's meal," check `daily_overrides` for today's date first; if none exists, fall back to the planned `menu_items` entry for today.

### `notifications`
Using Laravel's built-in notifications table (`php artisan notifications:table`) — no custom design needed.

---

## 6. Current Status

- [x] Vision, MVP scope, and roles defined
- [x] Full tech stack decided
- [x] Database schema designed
- [x] Project scaffolding steps documented (Laravel + Inertia + Vue + Tailwind + Sanctum, SQLite for local dev)
- [x] Scaffolding actually run and smoke-tested locally
- [x] Migrations & models built from schema
- [x] Super Admin dashboard (Company & Tiffin management, 1:1 pairing)
- [x] Tiffin Admin dashboard (Mon-Fri menu builder, Draft/Published states, Daily Overrides)
- [x] Company Admin dashboard (Assigned Tiffin details, Today's meal highlight, Override alerts)
- [ ] Notifications wired up
- [ ] API layer (`/api/v1`) for future mobile use
- [ ] Astro landing page

---

## 7. Future Phases (for context, not being built yet)

- **Phase 2:** Employee attendance, meal confirmations, holidays, better notifications
- **Phase 3:** Meal preferences, analytics, reports, delivery status
- **Phase 4:** Billing, HR integrations, mobile app (Flutter UI), multi-company kitchens, public APIs

The architecture (Laravel monolith, service-layer logic reused across Inertia + API) is deliberately structured so all of the above can be **added later without rewriting** what's built in Phase 1.