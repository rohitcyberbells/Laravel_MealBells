# MealBells — handover

Read this first if you are picking the project up cold. It is written for
whoever (or whatever) continues the work, and it assumes nothing.

Last updated 2026-10-09, at `adfeb69`.

> **`KT.md` and `PROGRESS_LOG.md` are stale.** They describe an MVP plan with
> MySQL and a Flutter app, none of which happened. Trust this file, `docs/`, and
> the git log — in that order. The commit messages are long on purpose and are
> the most reliable record of *why* something is the way it is.

---

## 1. What it is, in two paragraphs

MealBells decides how many lunches a tiffin vendor should cook for each company
each day. Employees (or their HR team, or the HR system) say who is **not**
eating; the engine computes `eligible − skips + extras`, and at the company's
**cutoff time** the number is **locked** into a snapshot and the vendor is told.
The locked figure never changes retroactively — a late change is recorded
separately as a post-cutoff adjustment, because the number the kitchen was given
is the evidence in any billing dispute.

Four roles: **super admin** (platform, pairs companies with vendors),
**company admin / HR**, **employee** (own skips only), **tiffin admin / vendor**
(counts, never who). Multi-tenant with no row-level database isolation — every
table carries `company_id` and every query is expected to scope on it, which is
why there is a cross-tenant test suite.

Screens and navigation: `SCREEN_FLOWS.md`. Engine rules: read
`app/Services/MealGuard.php` and `app/Actions/Meal/RecordSkip.php` — between
them they hold the guard order and first-source-wins, and almost every surprise
in this codebase traces back to one of those two.

---

## 2. Where things stand

| | |
|---|---|
| Branch | `main`, pushed, `origin/main == adfeb69` |
| Tests | **1159 green on SQLite**, **1159 green on PostgreSQL 18.6**, zero skipped |
| Pint | clean. `npm run build` clean |
| CI | `.github/workflows/tests.yml` — two jobs, SQLite and Postgres, both run migrate + full rollback |
| Deployed anywhere? | **No. Never.** See §6 |

Feature work is essentially complete. What remains is mostly "run it somewhere
real" plus a handful of product decisions only the owner can make.

### Recently finished

Batches A–E, then attendance Phase 1. In order:

- hardening: production guards, env examples, indexes, security headers, throttles
- accounts and data safety: forgot-password, super-admin bootstrap, user
  deactivation, company **and** tiffin-service archiving (soft delete, typed
  confirmation), operational prune, nightly backups with a **verified** restore
- performance: the day-walking pages no longer query inside their loops
  (employee dashboard 76 → 17 queries)
- `/health/ping` for an uptime monitor, plus deduped health alert emails
- the three notifications that had to leave the building, on a branded mail layout
- optional Sentry, DSN-gated, with scrubbing
- PostgreSQL made a first-class target; three Postgres-only defects fixed
- cross-tenant security sweep; revoked access now ends a live session
- company data export (CSV/ZIP) and employee anonymisation
- `docs/go-live.md`
- **attendance shadow mode** — see §5

---

## 3. Getting it running

```bash
composer install
npm ci
cp .env.local.example .env        # or: composer run setup
php artisan key:generate
php artisan migrate
php artisan db:seed --class=DemoSeeder
composer run dev                  # server + queue + vite together
```

All demo passwords are `demo1234`; the seeder prints every login. Full demo
runbook: `docs/demo-day.md`.

**If every page is blank**, delete `public/hot`. It is a marker Vite writes
while `npm run dev` is running; while it exists every page loads its assets from
a dev server that may not be running. It is gitignored, so it never shows in
`git status`, and stopping Vite does not remove it.

### Machine-specific things that will not exist on another computer

Everything below was set up on the office iMac. On a different machine it is all
absent, and **none of it is needed for the test suite or ordinary work** — the
suite runs on SQLite in memory.

| | |
|---|---|
| PHP | Laravel Herd, `~/Library/Application Support/Herd/bin/php` (8.4.16) |
| PostgreSQL | Postgres.app 18.6 on `localhost:5432`, user `imac`, no password. Databases `mealbells_test` (suite) and `mealbells_rehearsal` (demo data) |
| CyberPulse HRMS | the real app repo at `~/Desktop/Web_CyberPulse`, served on `:4040` — **do not edit or commit in it**. Orientation: `docs/cyberpulse-orientation.md` |
| CyberPulse local data | `~/cyberpulse-local-seed/` — mongod, seed, leave scenarios, and the attendance mock (§5) |
| mongod | `~/Downloads/mongodb-macos-x86_64-7.0.37/bin/mongod` |

To run the suite against PostgreSQL:

```bash
DB_CONNECTION=pgsql DB_DATABASE=mealbells_test DB_USERNAME=imac \
  vendor/bin/phpunit
```

---

## 4. Rules the owner has set, repeatedly

Follow these without being asked again.

| | |
|---|---|
| **Do not push** unless told to in that message | They say when |
| **Never edit or commit in `Web_CyberPulse`** | It is the live HRMS codebase. Reading it is fine and often useful |
| **Never test against the dev database** | Use a copy, or `mealbells_test`. This has gone wrong once — see §7 |
| **Do not remove tests.** Update an expectation if behaviour changed deliberately, and say so | |
| **Do not change the dark theme or colours** | |
| Separate commit per item, with Pint, `npm run build`, and test counts for **both** drivers reported | |
| Acme's demo HRMS secret is not to be touched | |
| If something already works, leave it alone | |

Deferred by the owner, not forgotten: **Calendar bulk UI** (the endpoint exists
and is tested; no screen) and the **landing page design** (guests redirect
straight to sign-in).

---

## 5. Attendance — read this before touching it

The CyberPulse developer suggested building the count from clock-in instead of
leave: *whoever clocked in gets a meal.* After reading what that would do to the
engine, the plan became **Phase 1 measures, Phase 2 decides.**

**Phase 1 is built and is live-off-by-default.** It reads attendance and reports
how many meals would **not** have been ordered — and changes no count, creates
no skip, and touches nothing the kitchen receives. There is a test that proves
exactly that against a day holding a leave, an extra and a locked snapshot.

```bash
php artisan hrms:pull-attendance ACME01 --dry-run
```

Report: **Reports → Attendance (shadow)**.

**The plan is to run it for two or three weeks and look at the number.** If the
saving is a meal or two a day, Phase 2 is not worth its risk. If it is fifteen,
there is something to show HR. Do not start Phase 2 before that number exists —
that is the whole point of the design.

Read `docs/attendance-design.md` before any attendance work. It records four
things Phase 2 **must** change, each of which is easy to miss and expensive to
discover late:

1. `CalculateExpectedMeals` returns `breakdown` as a hardcoded six-key array, and
   `MealCount.breakdown` is stored JSON — a seventh source counts in
   `skip_count` and vanishes from the breakdown
2. `DetectCountAnomalies` raises `spike` at 30% skips, which a normal day with
   absences clears easily — a daily escalation email until somebody mutes it
3. the leave pull **does not act on today at all** (its own comment says so), so
   attendance is the only mechanism operating there and its fail-safes carry the
   whole weight
4. `RecordSkip` catches a unique-violation `QueryException` *inside* a
   `DB::transaction`, which aborts the transaction on PostgreSQL — latent today,
   likely once two pulls can write for the same employee and date

### The local mock

CyberPulse has no attendance endpoint yet.
`docs/cyberpulse-attendance-endpoint-spec.md` is the ask, written to hand over
with no MealBells internals in it. Until it exists there is a stand-in in
`~/cyberpulse-local-seed/` — see `README-attendance-mock.md` there. It is
**outside git** on purpose: it holds an encryption key, and it gets deleted when
the real endpoint lands.

---

## 6. What is actually left

### First, and worth more than more code

**This has never run on a real server.** Everything was verified on one
laptop — PostgreSQL locally, production mode behind `php -S`, a real queue
worker, the scheduler. Not verified: HTTPS and HSTS, real SMTP delivery,
supervisor, a real cron, `pg_dump` on a server, assets on a real domain.

A small staging deploy following `docs/go-live.md` will teach more than any
further feature. Expect to find something.

### Decisions only the owner can make

- **Post-cutoff leave from the HR system** is refused, correctly, but
  **silently**. Should it tell anybody?
- What `attendance_source = 'integrated'` should mean long-term — Phase 1 gave it
  a meaning, but nobody has confirmed it is the right one
- **Who operates this?** Health alerts need a recipient and somebody on call
- Retention: prune defaults are sessions/notifications/HRMS payloads at 30 days
- What happens when a company leaves — archive, export, then what?
- Is 11:00 the right cutoff if attendance ever drives the count? Phase 1 will
  show how many people clock in between 10:55 and 11:00

### Open technical items

| | |
|---|---|
| Company dashboard still costs 33 queries | `CalculateExpectedMeals` runs once per forecast day; `base_eligible_count` is **not** date-dependent, so a range variant collapses five identical employee counts into one |
| `RestHrmsClient` and the HRMS command wiring have no `Http::fake()` coverage | |
| `concurrently` → `shell-quote`, 2 critical npm advisories | A devDependency, used only by `composer run dev`, never in the production build, commands come from `composer.json` not untrusted input. The "fix" is a major downgrade from the declared `^10.0.3`. **Left deliberately; owner's call** |
| `league/commonmark`, 1 medium + 1 high | **No fixed release exists.** Checked against our path: the medium does not apply (`html_input => 'escape'`), the high is reachable but bounded by a 255-character validation cap. Re-run `composer audit` monthly |

---

## 7. Traps that have actually bitten

Each of these cost real time here. They are not hypothetical.

**The test suite will run against a production database.** `phpunit.xml` pins
SQLite in memory through `<env>`, and those **cannot override a cached config** —
`bootstrap/cache/config.php` wins. So after `php artisan config:cache`, the suite
points at whatever that config names, and `RefreshDatabase` is willing to
`migrate:fresh` it. It happened: the suite ran against the rehearsal Postgres
database. `TestCase` now refuses outright, but know why.

**`php artisan serve` does not pass `DB_DATABASE` to its own subprocess.** A
"test against a copy" that uses it is silently testing against the real
database. This cost real data once. Use
`PHP_CLI_SERVER_WORKERS=8 DB_DATABASE=/path/copy.sqlite php -S 127.0.0.1:8000 -t public router.php`
and **verify with a marker row** before trusting it.

**macOS is case-insensitive and Linux is not.** Inertia v3 defaults its page path
to `resources/js/pages`; this project uses `js/Pages`. The two agreed on every
developer machine and on none of the servers — twelve tests failed the first time
CI ran. `tests/Feature/InertiaPageComponentTest.php` now reads the tracked case
out of **git** rather than asking a filesystem that lies.

**A failed INSERT aborts the whole transaction on PostgreSQL** and leaves it
usable on SQLite. Any `try { insert } catch (QueryException)` inside a
`DB::transaction` is a latent Postgres bug. Two were fixed; one remains in
`RecordSkip` (§5).

**`LOG_LEVEL=warning` silences `MAIL_MAILER=log` completely**, because the log
mailer writes at debug. "Check the log to see the email" reports nothing and
looks like a broken mailer.

**Eloquent model events do not fire on a builder mass-delete.** `Model::where(...)
->delete()` skips `deleted` hooks entirely — which silently broke a cache
invalidation until a test caught it.

**`assertSentTo()` does not count.** Every notification listener was bound twice
(framework auto-discovery *and* an explicit `Event::listen`), so vendors received
two identical emails per locked day, and the whole suite passed. Only
`assertSentToTimes()` bites.

**`actingAs` pins one model instance for the whole test**, and the auth guard
caches the user it resolved. A test that mutates the user and expects the next
request to notice will pass for the wrong reason. Sign in for real, or call
`$this->app['auth']->forgetGuards()`.

**Collection `where()` compares loosely, and `null == false` in PHP.** This made
every "we don't know" attendance answer count as an absence. Use `whereStrict`
wherever null is meaningful.

**A migration's `down()` can pass on one driver and fail on the other.** Dropping
a column that an index still references fails on SQLite; PostgreSQL drops the
index with it. Both CI jobs now run a full rollback.

**Run migrations on the dev database after writing one.** The suite uses
`:memory:`, so a new migration can be green in tests and the dev app still
500s on a missing column. Take a backup first — `php artisan mealbells:backup`.

---

## 8. Where the real documentation is

| | |
|---|---|
| `docs/go-live.md` | **Start here for a first deployment.** The ordered sequence |
| `docs/deploy.md` | Reference for each piece, by topic |
| `docs/backup-restore.md` | Restore steps first, because that is read under pressure. Both drivers verified |
| `docs/demo-day.md` | Running a demo on a laptop |
| `docs/hrms-event-contract.md` | The HRMS webhook and pull contract |
| `docs/attendance-design.md` | Attendance phases, decisions, and the four Phase 2 traps |
| `docs/cyberpulse-attendance-endpoint-spec.md` | For the CyberPulse developer. No internals |
| `docs/cyberpulse-orientation.md` | **Read before working on CyberPulse itself.** Their stack, how to run it locally, the encryption and timezone traps, and what looks wrong |
| `NEXT-SESSION.md` | Short-lived working notes. **Deliberately not committed** |
| `CLAUDE.md` / `AGENTS.md` | Conventions. Pint after every PHP change; `php artisan make:` for new files |

---

## 9. How to work here

The bar this codebase has been held to, since it is visible in every commit and
worth keeping:

- **Comments say why, not what.** Most non-obvious lines carry the reason they
  exist, usually the bug that caused them
- **A guard is not finished until it has been mutation-checked** — remove it,
  watch the test fail, put it back. Several tests here originally passed for the
  wrong reason and were only caught this way
- **Both drivers, every time.** SQLite for speed, PostgreSQL because that is what
  ships
- **Report honestly.** If something was not verified, the commit message says so.
  There are commits here that state plainly which half of a claim was tested
- Commit messages are long and explain the reasoning. Keep that; it is the only
  durable record of why
