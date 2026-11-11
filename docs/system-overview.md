# MealBells — system overview and review

What MealBells does end to end, how the code is laid out, whether that layout
holds up, what is still open, and what has to change on the CyberPulse side.

Written 2026-11-10 from a full read of the repository at `5ad4e05`, on the
Windows machine. For history and the reasons behind individual decisions, the
commit messages and `HANDOVER.md` remain the primary record.

---

## 1. The flow in one day

MealBells answers one question per company per day: **how many lunches should
the tiffin vendor cook?**

```
            ┌──────────── who is NOT eating today ────────────┐
            │                                                 │
 Employee ──┤ self skip / date range / recurring rule          │
 HR admin ──┤ manual skip, bulk skip, Leave/WFH CSV            ├──► skips table
 CyberPulse ┤ approved leave, pulled every 15 min (hrms:pull)  │   (one row per employee per day,
 Webhook ───┘ signed POST /api/hrms/{code}/events              │    first source wins)
                                                              │
 HR admin ──── guest / visitor extras ──────────────────────► meal_adjustments
                                                              │
                       count = eligible − skips + extras      ▼
                                                     CalculateExpectedMeals
                                                              │
   cutoff − 30 min   summary prepared, anomalies detected     │
   cutoff − 15 min   unreviewed anomaly escalated             │  mealbells:process-cutoff
   cutoff − 5 min    attendance read (shadow only)            │  (runs every minute)
   cutoff            count LOCKED into meal_counts ───────────┘
                              │
                              ▼
               vendor notified, sees counts on Preparation screen
               (counts only, never names)
                              │
   after cutoff   any change is a post-cutoff adjustment (meal_count_changes);
                  the locked number itself never changes
```

The locked number never changes because it is the figure the kitchen was
given, and in a billing dispute that figure is the evidence.

### A worked example

ACME has 40 active, meal-eligible employees and an 11:00 cutoff.

| Time | What happens | Count |
|---|---|---|
| Mon evening | Ravi skips Tuesday from the portal (`self`) | 39 |
| Tue 08:00 | CyberPulse approves Priya's leave; the next pull writes a `leave` skip | 38 |
| Tue 09:30 | HR adds 3 visitors as extras | 41 |
| Tue 09:45 | Ravi's Friday recurring rule does nothing today | 41 |
| Tue 10:30 | Summary prepared; HR can review and confirm early | 41 |
| Tue 11:00 | Auto-locked as `auto_confirmed`; vendor notified | **41 locked** |
| Tue 11:20 | Someone goes home sick; HR records a post-cutoff change | still 41 locked, change logged beside it |

---

## 2. Roles

| Role | Sees and does | Never sees |
|---|---|---|
| Super admin | Creates companies and vendors, pairs them 1:1, resets admin passwords, deactivates users, archives tenants, rotates HRMS secrets, Health page | — |
| Company admin (HR) | Daily count and confirm, employees (CSV import, logins, anonymise), skips, extras, calendar holidays, recurring rules, settings, HRMS connection, reports, data export | Other companies |
| Employee | Own next 7 days, skip a day or a range, recurring rules, cancel own `self`/`recurring` skips | Anyone else; cannot cancel `hr`/`leave`/`wfh` skips |
| Tiffin admin (vendor) | Weekly menu, today's override, preparation view of counts | Employee names |

There is no row-level database isolation. Every tenant table carries
`company_id` and every query must scope on it. `tests/Feature` holds a
cross-tenant suite for exactly that reason.

---

## 3. How the code is laid out

```
routes/web.php            role groups: super-admin, tiffin-admin, company-admin, employee
routes/api.php            the HRMS webhook, signed, throttled
routes/console.php        the scheduler — every recurring job lives here

app/Http/Controllers/     thin: validate, call an Action, return an Inertia page
app/Actions/              the business logic, one class per operation
  Meal/                   RecordSkip, CalculateExpectedMeals, ConfirmDailyCount,
                          RecordPostCutoffChange, DetectCountAnomalies, ...
  Employee/ Skip/ Recurring/ Calendar/ Company/ Hrms/ Menu/
app/Services/
  MealGuard.php           every rule check, in one fixed order
  MealCutoff.php          has today's cutoff passed, in the company's timezone
  MealCalendar(.Cache)    is this a meal day (weekday + holiday/working overrides)
  HealthChecks.php        what the Health page and alert emails report
  Hrms/                   adapters (CyberPulse, Generic), client, event mapper
app/Enums/                MealRuleReason, SkipOutcome
app/Console/Commands/     cutoff, recurring generator, HRMS pulls, backup, prune, alerts
resources/js/Pages/       21 Vue pages, one folder per role
resources/landing/        Astro landing page, built separately into public/landing
tests/Feature/            96 files
```

### The two files that explain most behaviour

**`MealGuard::assertEditable`.** Every write goes through these checks, in this
fixed order:

1. cross company
2. inactive employee
3. not a meal day
4. past date
5. more than 60 days ahead
6. count locked
7. cutoff passed
8. invalid quantity, type or source

**`RecordSkip`.** It applies *first source wins*:

- An existing active skip is never overwritten.
- A cancelled skip can be revived by a person (`hr`, `self`).
- An automated source can revive only a cancellation it made itself, on the
  same `external_ref`.

Every skip returns one of four outcomes: `CREATED`, `REACTIVATED`,
`ALREADY_SKIPPED` or `BLOCKED_CANCELLED`.

### Scheduler (`routes/console.php`)

| Job | When |
|---|---|
| `mealbells:process-cutoff` | every minute — summary, escalation, lock |
| `hrms:pull --all --before-cutoff` | every minute; acts just before each company's cutoff |
| `hrms:pull --all` | every 15 minutes (configurable) |
| `hrms:pull-attendance --all --before-cutoff` | every minute; acts 5 min before cutoff, only for companies with attendance switched on |
| `hrms:reconcile` | every 5 minutes |
| `mealbells:generate-recurring-skips` | hourly |
| `mealbells:health-alerts` | every 5 minutes |
| `mealbells:backup` | 02:30 daily |
| `model:prune`, `mealbells:prune-operational-data` | 03:00 / 03:15 daily |

The scheduler and the queue worker both have to run in production. Without
the scheduler nothing ever locks, and without the queue worker no mail is sent.

---

## 4. HRMS integration (CyberPulse)

There are three paths. Each one hands its events to the same pipeline, so the
guards, the dedupe, the audit trail and the Health view are the same whichever
way an event arrives.

| Path | How | Status |
|---|---|---|
| **Leave pull** | `hrms:pull` signs in as a CyberPulse user (`POST /api/employee/login`) and reads `GET /api/leave/fetchAll`. Eight whitelisted fields are kept and the rest is discarded at parse time. Approved, future leave becomes `leave` skips with `external_ref = cp:leave:{id}:{state}` | Built, tested against a local CyberPulse |
| **Webhook** | `POST /api/hrms/{company_code}/events`. HMAC-SHA256 signature (preferred) or a bearer token | Built. CyberPulse does not send webhooks today |
| **Attendance** | `hrms:pull-attendance` calls `GET /api/integration/attendance/daily?date=&tz=` with `X-API-Key` | **Shadow mode only.** It reports what it *would* have saved and changes no count. The endpoint does not exist in CyberPulse yet |

Cancellation is inferred: CyberPulse deletes a withdrawn leave instead of
marking it cancelled, so the only signal is that the leave is missing from the
next fetch. MealBells applies three guards before cancelling anything:

- It cancels nothing unless the fetch clearly succeeded.
- It only touches skips that carry its own `cp:leave:` reference.
- It refuses a batch that would cancel more than 30% of the leaves it holds.

Connection settings are per company, encrypted in the database, and set from
**Settings → HRMS**. Nothing about the vendor is in `.env` or in the code. In
production the base URL must be public https.

Details: `docs/hrms-event-contract.md`, `docs/attendance-design.md`,
`docs/live-trial.md`.

---

## 5. Is the structure right?

**Yes. For what this application does, the structure is sound,** and better
than most Laravel codebases of this size.

What is right:

- **Logic lives in Actions, not controllers.** A future mobile API, or another
  HRMS adapter, can reuse the same classes. This was the stated goal from the
  start and it held.
- **One guard order and one skip rule.** Every entry point (portal, HR screen,
  CSV, recurring generator, webhook, pull) goes through `MealGuard` and
  `RecordSkip`. That is why the different sources cannot disagree.
- **The locked snapshot is immutable,** and late changes are a separate table.
  That is the correct model for something that ends up in billing.
- **The HRMS layer uses adapters and a single event pipeline,** with the
  privacy boundary at parse time. Adding a second HRMS means adding an adapter,
  not new skip logic.
- **The tests are serious.** 96 feature files, a cross-tenant sweep, migration
  rollback, and a CI job that runs SQLite and PostgreSQL side by side.

What is weaker. None of it is structural, and all of it is small:

| | |
|---|---|
| Roles and statuses are bare strings | `'super_admin'`, `'active'` and the skip sources are compared as literals across controllers and actions. Only two enums exist. A typo compiles and fails silently. Worth a `Role` and `SkipSource` enum eventually |
| Actions are created with `new` in places | e.g. `ProcessDailyCutoff`, `ConfirmDailyCount`. It works, but it bypasses the container, so these cannot be swapped in a test |
| Notes files inside `app/` | `app/Console/Commands/Phase1milstones.md` and `app/Http/Controllers/CompanyAdmin/Phase2Milestone.md`. They belong in `docs/` or should be deleted |
| Stale top-level docs | `KT.md`, `PROGRESS_LOG.md` and `SYSTEM_HANDOVER.md` describe an older plan (MySQL, Flutter, 133 tests). `README.md` is still the Laravel default. A new reader is misled before reaching `HANDOVER.md` |

---

## 6. Findings from this check

| # | Severity | Finding |
|---|---|---|
| 1 | **High** | **`composer audit` reports new advisories**, most published after the handover was written: `symfony/http-foundation` (one *high*: ReDoS through the `Cache-Control` header, plus host and proxy-trust issues), `symfony/http-kernel`, `symfony/routing`, `symfony/mime`, `symfony/yaml`. These are patch releases within the existing constraints, so `composer update "symfony/*"` should clear them. It is a dependency change, so it needs the owner's go-ahead. The two `league/commonmark` advisories were already assessed in `HANDOVER.md` |
| 2 | Medium (latent) | **`RecordSkip` catches a `QueryException` inside `DB::transaction`.** On PostgreSQL a failed INSERT aborts the whole transaction, so the fallback re-read fails too. It only triggers when two writers race on the same employee and date, which becomes likely once attendance Phase 2 runs alongside the leave pull. Already listed in `HANDOVER.md` §5 |
| 3 | Low | **The "locked" check is not under a row lock.** `MealGuard` checks `locked_at` outside any transaction (its comment says `lockForUpdate`, the code does not use one), and `ConfirmDailyCount` computes the count before its own transaction. A skip that lands in the same instant as a lock can be stored but missing from the snapshot. The window is milliseconds and the cutoff guard narrows it further. Note it; do not chase it before go-live |
| 4 | Low | **A missed cutoff is never caught up.** `process-cutoff` only looks at *today*. If the scheduler is down from before the cutoff until after midnight, that day never locks and the vendor is never told. The Health page's scheduler heartbeat is what is meant to catch this, so it needs a real alert recipient |
| 5 | Low (UI) | The HRMS secret panel on `SuperAdmin/Dashboard.vue` (line ~189) is nested inside the tab row and renders as a flex item beside the tabs. Two-line fix |
| 6 | Done | The company dashboard forecast that `NEXT-SESSION.md` called dead data is now shown. Calendar bulk now has a screen (`b9aac11`) |
| 7 | Environment | **This Windows machine cannot run the app as it stands.** `C:\xampp\php` is PHP 8.0.30 and the project needs ≥ 8.4.1. Install PHP 8.4 (Laravel Herd for Windows is the simplest) and put it ahead of XAMPP on `PATH` |
| 8 | Environment | `package-lock.json` shows as modified. That is a Windows `npm install` rewriting it (name changed, `libc` fields dropped), not a real change. Do not commit it: `git checkout package-lock.json` |

### Verification run on this machine

- **Pint:** `pint --test` passes, project-wide.
- **Tests:** a full PHPUnit run on Windows was stopped after 392 tests with 0 failures, because it slowed the local server. The rest of the suite has not been run since. `InertiaPageComponentTest` fails 4 cases on Windows only (it shells out with `2>/dev/null` and splits paths on `/`), which is unrelated to the code under review.
- **Tenant scoping:** every controller method that takes a route-bound model
  (`Employee`, `Skip`, `CompanyCalendarDay`, `RecurringSkip`, `MealAdjustment`)
  was checked by reading the code. Each one compares `company_id` (or
  `employee_id` for the employee portal) before acting, either in the
  controller or in the Action it calls.

**Not verified here:**

- PostgreSQL (none is installed on this machine)
- a browser pass
- any real server

The application has **never been deployed**. `docs/go-live.md` is the
runbook for that, and a staging deploy will teach more than further feature
work.

---

## 7. What has to change in CyberPulse

CyberPulse is a separate codebase:

- Node 22 + Express + Mongoose on MongoDB, React on CRA
- in production at cyberpulse360.com
- **no tests at all**

Read `docs/cyberpulse-orientation.md` before touching it.

### 7.0 State of the CyberPulse code, checked 2026-11-10

**Where it is:** `E:\Web_Cyberpulse`, remote `info-cyberbells/Web_Cyberpulse`.
Everything below was read from `origin/main` at `2512d48`, after a
`git fetch`. Nothing was checked out or edited.

**What to know before working in that clone:**

- It is **13 commits behind** `origin/main`.
- It has someone's uncommitted work: a modified
  `frontend/src/constants/apiConstants.js` and an untracked
  `backend/fix_notifications.js`. Pull carefully so that work is not lost.
- Commit `9f20360`, which the orientation doc was written against, is not in
  this history. The office iMac copy was a different clone. Everything below
  was re-checked against the live branch rather than taken from that doc.

| | Status on `origin/main` |
|---|---|
| Attendance endpoint (`/api/integration/attendance/daily`, `X-API-Key`) | **Not built.** No integration route and no API-key auth exist anywhere in `backend/` |
| Leave pull dependencies: `POST /api/employee/login`, `GET /api/leave/fetchAll` | **Still compatible.** Same route, same `{ success, data }` shape. It still populates `employeeId` with `name email …`, and the leave fields MealBells keeps are unchanged. JWTs now last 30 days (`695939a`), which only means fewer re-logins |
| Organisation timezone | **CyberPulse stores none.** No model has a timezone field. The new endpoint must therefore honour the `tz` query parameter MealBells already sends, rather than looking up a zone of its own |
| `.lean()` in `superAdminController` (line 94) | Still there |
| Aggregation sorting and grouping on encrypted `clockInTime` (`attendanceController.js` 423–428) | Still there |
| `setHours(0,0,0,0)` day boundaries | Still there, in `attendanceController` (5), `superAdminController` (2) and `TaskController` (3). `moment-timezone` is a dependency but only `HolidaysController` uses it |
| `404` for an empty attendance list | Still there (`fetchAllAttendance`, `fetchAllAttendanceByDate`) |
| `cors()` with no options, `express.json({ limit: '500mb' })` | Still there |
| Hardcoded `localhost:4040` in `MessageBubble.js`, prod URL in `socketService.js` | Still there |
| **New:** `app.set('trust proxy', true)` together with an IP-keyed rate limiter | Any client can send its own `X-Forwarded-For`, so `apiRateLimiter` can be bypassed. Trust only the real proxy hop (`'loopback'` or `1`) |
| **New:** `GET /api/leave/fetchAll` has no date filter | It returns the organisation's entire leave history on every pull, every 15 minutes, and it grows forever. A `from` date parameter would fix it, and MealBells only needs leave from today onward |
| Tests | Still none |

**Where to build the endpoint:**

- **Controller:** `getAllEmployeesAttendanceAndTasksByDate` (line 347,
  routed as `/currentEmpAttendance`) is the closest existing code to base the
  new handler on.
- **Router:** mount it in `index.js` as its own router, e.g.
  `app.use('/api/integration', routerIntegration)`, with its own API-key
  middleware instead of `authenticateToken`.
- **API key:** `organizationSettingsModel` is the natural home for a hashed
  per-organisation key.

> The standing rule until now has been *read CyberPulse, never edit or commit
> in it*, because it is a live system with no tests. If that rule is now lifted,
> keep the reasons in mind:
>
> - Work on a branch.
> - Run it against the local seed harness, never the live database.
> - Get the change reviewed by whoever owns CyberPulse.
> - Do not push straight to the branch that deploys.

### 7.1 Required — the attendance endpoint

The full spec, written to hand over: `docs/cyberpulse-attendance-endpoint-spec.md`.
In short:

- [ ] **New route** `GET /api/integration/attendance/daily?date=YYYY-MM-DD&tz=Asia/Kolkata`.
      Mount it as its own router. It must not sit under `/api/attendance`, whose
      bare `GET /:employeeId` would shadow a new single-segment route
- [ ] **Auth by `X-API-Key`.** Use a per-organisation key that can be rotated
      without a code change, not an employee JWT. Return `401` for a missing or
      wrong key
- [ ] **One row per active employee, including absent ones.** Active means
      `status` not in `['0', 0]`. Each row carries `employee_id`, `email`,
      `clocked_in`, `clock_in_at` and `is_wfh`
- [ ] **`clocked_in` is three-valued:** `true`, `false`, or `null` when a record
      exists but its time cannot be read. Only `false` can stop a meal
- [ ] **Day boundary in the organisation's timezone.** Use `moment-timezone`,
      not `setHours(0,0,0,0)`, which uses the server's zone. Echo `timezone`
      back in the response: MealBells rejects an answer whose timezone does not
      match the one it asked for
- [ ] **Read `clockInTime` from hydrated Mongoose documents, or call
      `decryptTime()`.** `.lean()` and aggregation pipelines skip the
      `post('init')` decrypt hook and return `enc:…` ciphertext
- [ ] **`200` with `"employees": []` when there is nothing.** Never answer
      `404` for an empty result. Return `400` for a bad `date`
- [ ] **Send nothing else.** No selfies, location, breaks, emergency reason,
      salary, tasks or department
- [ ] **Rate limit** of about 60 requests per minute
- [ ] **Serve it on the same host as the login API.** MealBells uses one
      `pull_base_url` for both. A separate host needs a small MealBells change
      (a second URL column and field)
- [ ] **A public https hostname with a valid certificate.** MealBells refuses
      http and private addresses in production
- [ ] **Seed some attendance rows in their dev data** (on time, late, WFH,
      absent) so the endpoint can be tested end to end

### 7.2 Recommended — for the leave pull that already works

- [ ] A **dedicated integration account** for the leave pull. Today it signs in
      as an ordinary employee, which shows up in their reports. Better still,
      accept the same `X-API-Key` on a trimmed leave endpoint and drop the
      password login entirely
- [ ] Optionally, send **webhooks** for leave approved, withdrawn and rejected,
      to the contract in `docs/hrms-event-contract.md`. That would replace
      inferring cancellations from missing rows

### 7.3 Bugs in CyberPulse found while reading (their call)

| | |
|---|---|
| `superAdminController.getOrgAttendance` uses `.lean()` | Returns encrypted clock times to its callers |
| `attendanceController` aggregation sorts and groups on `clockInTime` | It is sorting ciphertext with a random IV, so `lastClockIn` is effectively random |
| `setHours(0,0,0,0)` day boundaries | Wrong day on a UTC host for the first 5½ hours in India. Already patched by hand once (`scripts/dhaanOrgFixClockOutTime.js`) |
| `404` for an empty list | `fetchAll`, `fetchAllAttendanceByDate` |
| `http://localhost:4040` hardcoded in `components/Chat/MessageBubble.js` | Chat attachments cannot work in production |
| Production socket URL hardcoded in `services/socketService.js` | A local frontend talks to the live server |
| `app.use(cors())` with no options, `express.json({ limit: '500mb' })` | Any origin, and half a gigabyte per request, on an API serving selfies and locations |
| Dead `copy` and dated files in the tree | Easy to edit a file nothing imports |
| `trust proxy: true` with an IP-keyed limiter | The rate limit can be bypassed with a forged `X-Forwarded-For` |
| `leave/fetchAll` has no date filter | Full leave history on every pull |

The first three produce wrong answers. `trust proxy` is a security fix. The
rest are hygiene.

### 7.4 Once the endpoint is live — the MealBells side

No code change is needed, only configuration (`docs/live-trial.md`):

1. Settings → HRMS: set `pull_base_url` (https) and `attendance_api_key`.
2. Mark the trial employees `attendance_source = integrated`.
3. Run `php artisan hrms:pull-attendance ACME01 --dry-run` by hand after 10:30
   IST, then without `--dry-run`.
4. Switch on `attendance_absence_enabled`; the scheduler takes it from there.
5. Run it for two to three weeks and read **Reports → Attendance (shadow)**.
   Only start Phase 2 (attendance actually changing the count) if the saving is
   worth it, and fix finding #2 first.

---

## 8. Order of work from here

1. Fix the local setup: PHP 8.4, then `composer run setup`, then the suite
   green on this machine.
2. Update the Symfony packages (finding #1) once approved, and re-run the
   suite.
3. Build the CyberPulse attendance endpoint (§7.1), against their local seed.
4. Deploy MealBells to staging following `docs/go-live.md`.
5. Connect the two, run the shadow trial, and decide on Phase 2 from the
   number.
6. Tidy when convenient: stale docs, `.md` files in `app/`, the UI nesting bug,
   enums for roles and sources.
