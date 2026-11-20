# Next session — the CyberPulse trial is live and working

_Rewritten 2026-10-12, end of day. Committed on 2026-11-11, by request — it had
been kept out of git until then. **Section 0 added 2026-11-13** and is the
current state; everything below it is older and kept as background._

---

# 0. 2026-11-13 — where it actually stands

Written for whoever picks this up next, including a future session of this
assistant. Read this section before anything else in the file.

| | |
|---|---|
| MealBells `HEAD` | `a831758`, in sync with `origin/main`, tree clean |
| CyberPulse `main` | `d146d89` — endpoint merged, deployed, **and answering** |
| What is blocked | Nothing. |

> **Updated 2026-11-16.** The admin fixed the `.env` line over the weekend. The
> endpoint returns real data, 21 Cyberbells employees are imported into `CYB01`,
> and the shadow pull runs clean with **nothing unmatched**. §0.2 is kept as the
> record of what was wrong; §0.7 is the first live result and §0.8 is the one
> piece of work still outstanding.

## 0.1 CyberPulse is deployed — verified live, not assumed

Checked by hand on 2026-11-13 with read-only requests. No code was changed on
either side.

| Check | Result |
|---|---|
| `GET /api/integration/attendance/daily` | `401 {"message":"Invalid or missing API key"}` — that string exists only in **our** controller, so the new code is running |
| Host | `https://cyberpulse360.com` → `38.146.28.229`, a public address |
| TLS | Let's Encrypt, valid 5 Nov 2026 → **3 Feb 2027** |
| `GET /api/leave/fetchAll` on the same host | `401 {"success":false,"message":"Access token required"}` |

That last row settles the open question in `docs/cyberpulse-push-plan.md` §7
empirically rather than by reading their source: the login/leave API and the
attendance endpoint are served by **the same host**, so one `pull_base_url`
addresses both and no second column is needed.

**The value to set is exactly:**

```
pull_base_url = https://cyberpulse360.com
```

No `/api` suffix — `CyberPulseClient::url()` appends `/api/...` itself
(`app/Services/Hrms/CyberPulse/CyberPulseClient.php:351`).

**`trust proxy: 1` is no longer a guess.** The response carries `server: nginx`
and `x-powered-by: Express`, with no `cf-*` headers at all. The chain is
client → nginx → Express, exactly one hop, so `1` is the right number. Revisit
only if Cloudflare is ever put in front, which would make it `2`.

## 0.2 The blocker: the env line is missing its organizationId

The admin set the variable but wrote only the key:

```
MEALBELLS_API_KEYS=e11138cd…9efa                                  ← what is there
MEALBELLS_API_KEYS=e11138cd…9efa:686e0094275619eb4b6bddab         ← what it must be
```

`configuredKeys()` splits each entry on its **last colon** and rejects anything
where `lastIndexOf(":") <= 0`. With no colon the single entry parses to `null`,
is filtered out, and the list of configured keys is empty — so *every* key
presented gets a 401, however correct it is. Retested with the real key on
2026-11-13: still 401, as predicted.

`686e0094275619eb4b6bddab` is Cyberbells ITES Pvt. Ltd.'s `organizationId`,
read off the browser console on their own dashboard (`services.js:6`).

**The key itself is deliberately not written down here.** It lives in their
`.env` and, once the trial starts, in MealBells' encrypted
`company_hrms_connections.attendance_api_key`. Ask the admin for it; do not
paste it into a tracked file.

After they fix the line and **restart the process** (dotenv reads `.env` once at
startup), this one command says whether it worked:

```bash
curl -sS -H "X-API-Key: <key>" \
  "https://cyberpulse360.com/api/integration/attendance/daily?date=$(date +%F)&tz=Asia/Kolkata"
```

| Response | Meaning |
|---|---|
| `200` + an `employees` array | Working. Go to §0.4. |
| `401` | The line is still wrong, or the process was not restarted |
| `500 Integration key is misconfigured` | The organizationId is not a valid ObjectId |

## 0.3 The dev database was changed, by request

**This overrides the standing rule at the end of this file.** On 2026-11-13 the
user asked for company 1's seed data to be removed so the real Cyberbells
employees could take its place. It was done deliberately, not by accident.

| | Before | After |
|---|---|---|
| Company 1 | `ACME01` · "Acme Industries" | **`CYB01`** · "Cyberbells ITES Pvt. Ltd." |
| Employees | 30 fake | **0** |
| Also deleted | — | 9 skips, 1 recurring skip, 4 meal counts (+2 change rows), 2 adjustments, 3 employee logins |
| Kept | — | both `company_admin` logins, `Asia/Kolkata`, cutoff `11:00` |

The two admin logins keep their original addresses — `hr@acme.test` and
`hr.backup@acme.test` — so signing in still works; only their display names
were changed. Orphan rows were checked for afterwards and there are none.

**Company 2 (`NWND01`, Northwind Traders, 10 employees) was not touched** and is
still there as demo data.

> ⚠️ **The company code changed.** Every command in `docs/live-trial.md` that
> says `ACME01` now needs `CYB01`:
> ```bash
> php artisan hrms:pull-attendance CYB01 --date=$(date +%F) --dry-run
> ```

A backup of the database as it was before this was taken first, in the session
scratchpad:

```
/private/tmp/claude-501/-Users-imac-Desktop-RED-MEALBELLS/4b66bb98-558f-4dc8-99c8-fe7c8d2c8485/scratchpad/database-backup-20261113-142339.sqlite
```

That directory is temporary. **Copy it somewhere durable if the old seed data is
wanted back**, because a reboot may take it.

## 0.4 What happens once the key works

Nothing here needs anyone but us, and none of it needs code.

1. **Fetch the employee list.** The endpoint returns one row per active employee
   of the organisation: `employee_id` (their Mongo `_id`), `email`,
   `clocked_in`, `clock_in_at`, `is_wfh`.
2. **Import them into `CYB01`.** MealBells already has a CSV import
   (`app/Actions/Employee/ImportEmployeeCsv.php`, with preview and confirm
   routes and a UI modal). Columns: `employee_code` and `name` are required;
   `external_id`, `email`, `attendance_source`, `is_meal_eligible` and `status`
   are optional. Put the Mongo `_id` in **`external_id`** and set
   `attendance_source` to `integrated` in the same file. Import upserts on
   `employee_code`.
3. **Settings → HRMS:** base URL and key.
4. **Shadow pull**, `--dry-run` first, then for real.

**Two things that will bite at step 2:**

- **Matching is `external_id` → `employee_code` → `email`**
  (`HrmsEventMapper::resolveEmployee`). CyberPulse only ever sends a Mongo `_id`
  and an email, so `external_id` is the only strong key available. Get it right
  at import time or every person lands in the report's "not matched" column and
  "would-be absent" reads 0.
- **CyberPulse has no employee-code field at all.** Its `employeeModel.js` has
  `name`, `email`, `department`, `position`, `jobRole`, `type`, `status` and no
  identifier besides `_id`. MealBells requires `employee_code` and requires it
  unique, so one has to be invented — from the email's local part, or a
  `CP001…` sequence. **This is still undecided.**
- **Names are not in the attendance response.** `select("_id email")` is all it
  asks for. Either get the names from `GET /api/employee/fetchAll` with an admin
  JWT (their login, which the leave pull needs anyway), or derive placeholders
  from the email and fix them later.

## 0.5 A pre-existing security hole in CyberPulse — not ours, and serious

Found on 2026-11-13 while proving that our commits were not responsible for
anything visible in their browser console.

```
GET https://cyberpulse360.com/api/employee/detail/<id>     → 200, no token
```

It returns the complete employee document: name, personal email, home address,
phone, department, joining date — **and the bcrypt `password` hash**. There is
no authentication on the route.

```js
// backend/route/employeeRoute.js:14
routerEmployees.get('/detail/:id', detailEmployee);     // no authenticateToken
```

That router has no blanket `routerEmployees.use(authenticateToken)` — unlike
`attendanceRoute.js`, which guards its whole router on line 6. Only `/fetchAll`
is protected, per-route. `/api/employee/getDocuments/:employeeId` is open the
same way.

**Introduced in `09bcf36`, Prince Patial, 2025-10-10, "code initiated"** — the
repository's first commit, more than a year before our work. Verified by reading
that line out of commit `9f20360`, the parent of our branch.

Why it matters beyond the obvious: Mongo ObjectIds embed a timestamp and a
counter, so they are partly predictable, and a leaked bcrypt hash can be
attacked offline where no rate limit applies.

Suggested fix, **not applied** — that repo is read-only to us without approval:

```js
routerEmployees.get('/detail/:id', authenticateToken, detailEmployee);
```

plus `.select('-password')` in the controller, because a password hash should
not appear in any response even an authenticated one. This should be raised
with whoever owns that repository.

## 0.6 Our commits did not cause any of it

Worth keeping written down, because the question came up and will again.

- `git show --stat bd7accf 06cb59c` → **three backend files**, 282 insertions,
  zero frontend files.
- `grep -rn "api/integration" frontend/src` → **nothing**. Their frontend does
  not know the endpoint exists and never calls it.
- The only references to our files anywhere are `index.js:38` (the import) and
  `index.js:95` (`app.use("/api/integration", …)`), a prefix no existing route
  shares.
- The endpoint has never returned a single byte: it has answered 401 to every
  request ever made to it.

The one change of ours that affects every route is `trust proxy: true → 1`, and
it makes the application *more* restrictive, not less.

## 0.7 2026-11-16 — the first live read, and it works

The admin added the `:organizationId` half and restarted. Checked at 10:03 IST:

```
GET /api/integration/attendance/daily?date=2026-11-16&tz=Asia/Kolkata
→ 200, 21 employees
```

Both of `CyberPulseClient`'s guards passed on the real response: the `date` came
back as asked, and so did the `timezone`. **Zero `clocked_in: null`** — so
`TIME_ENCRYPTION_KEY` on the deployed host is the one the records were written
with, and the three-valued fail-safe was not needed.

What was then done on the MealBells side, all of it configuration:

| | |
|---|---|
| Employees | 21 imported into `CYB01` from the endpoint's own response |
| `external_id` | each person's Mongo `_id` — the strong matching key |
| `attendance_source` | `integrated`, set in the same CSV |
| `name` / `employee_code` | **derived from the email local part**, by the user's choice |
| HRMS connection | `pull_base_url` + `attendance_api_key`, encrypted |
| `attendance_absence_enabled` | on |

The generated CSV and the raw response are in the session scratchpad
(`cyb01-employees.csv`, `cp-2026-11-16.json`) — temporary, so copy them if they
are wanted.

**The first day was recorded for real at 11:00 IST**, after the cutoff rather
than before it, so the numbers are final rather than a snapshot of who had
arrived so far. `last_attendance_pull_status` is `ok` and 21 `attendance_days`
rows were written, one per employee, `source: cyberpulse`.

The shadow-mode promise held, and this is the check worth repeating on any later
day: **`skips` where `source = 'attendance'` is 0**, and `meal_counts` is still
empty. The read recorded what it saw and changed nothing.

The first `--dry-run`, at 10:17 IST, before any of that:

```
Eligible employees (attendance read)              21
Clocked in by cutoff                              15
Absent by cutoff                                   6
Unknown (no answer from the HR system)             0
Not matched to an employee                         0
Already not eating (leave, WFH, self, HR)          0
WOULD-BE ABSENT (meals not ordered, if enabled)    6
```

**"Not matched: 0" is the row that matters.** Every Mongo `_id` resolved to a
MealBells employee, which is what the `external_id` import was for.

Two caveats on that `6`:

- **It was read at 10:17 against an 11:00 cutoff**, so it counts people who had
  not arrived *yet*. The count had already moved from 14 to 15 in the fourteen
  minutes between the raw fetch and the pull. A number only means something when
  read at ~10:55, which is when the scheduler reads it.
- **"Already not eating" is 0 because the leave pull is not configured** —
  `pull_email` and `pull_password` are unset. Anyone on approved leave is
  therefore counted as absent rather than as already not eating. Harmless for
  testing the attendance path; it has to be fixed before the number is used for
  anything.

Four names came out of the email derivation run together and want fixing by hand
in the UI: `Sangeetarathod`, `Rruchibharadwaj`, `Tanishkasingh`, `Saveenmehta`.
`Hr` (`hr@cyberbells.com`) and `Alex Carter` (`alex.carter@cyberpulse.com`) look
like a role account and a test account rather than people who eat lunch — worth
checking before the counts are taken seriously.

Real names can be had at any time from `GET /api/employee/fetchAll`, which needs
only an ordinary logged-in JWT, not an admin one, and is scoped to the caller's
own organisation. The token sits in `JSON.parse(localStorage.getItem('user')).token`
on their site. A re-import upserts on `employee_code`, so it would correct the
names without creating duplicates.

### The rest of the seed data was purged the same day

On request, everything left over from the demo seed was deleted: company 2
(`NWND01`, Northwind Traders) entirely — 10 employees, 5 users, 8 skips, 4 meal
counts, 2 adjustments, its tiffin assignment and settings — company 3
(`DBG001`), the seeded "Founders Day" holiday for both companies, 4 demo HRMS
webhook events and 18 notifications. Orphans were checked for afterwards: none.

Backup taken first, in the session scratchpad:
`backup-before-purge-20261116-104011.sqlite`. **Temporary — copy it if it is
wanted.**

What remains is one company, `CYB01`, with 21 real employees, four users, and
the vendor.

**Three fake things were deliberately kept, and the user confirmed on
2026-11-16 that they stay for now:**

| Kept | Why |
|---|---|
| `tiffin_services` #1, "Annapurna Gourmet Tiffin", and the `Chef Rahul` login (`vendor@mealbells.test`) | The only vendor in the table, and it is assigned to `CYB01`. Deleting it leaves Cyberbells with no tiffin service and no meal count can be produced at all. |
| 2 weekly menus, 10 menu items | Hang off that vendor |
| `hr@acme.test`, `hr.backup@acme.test` | These are the Cyberbells admin **logins**. Only the display names were changed; changing the addresses would change how the user signs in. |

`Platform Root` (`root@mealbells.test`, super admin) belongs to the platform
rather than to any company, so it was left alone.

Replacing any of these with the real vendor, menu or addresses is configuration,
not code.

## 0.8 Outstanding: a second organisation, "Dhaam"

Raised by the user on 2026-11-16 and **deliberately deferred** — noted here so it
is not lost.

There is a second company, Dhaam, with its own `organizationId`, whose tiffin
count also has to be produced. **No code change is needed**; the design already
carries it.

- **CyberPulse:** `configuredKeys()` splits `MEALBELLS_API_KEYS` on commas, so a
  second pair is appended — `<key1>:<org1>,<key2>:<org2>` — with its own key
  from `openssl rand -hex 32`, then a restart. A key can only ever read the
  organisation bound to it, so neither company can see the other's data.
- **MealBells:** Dhaam becomes its own company row with its own
  `company_hrms_connections` record. `pull_base_url` is the same host; the key
  is different. Employee matching is already scoped by `company_id`, so two
  people sharing an email across companies cannot collide.
- **The scheduler needs nothing.** `hrms:pull-attendance --all` selects every
  company whose `attendance_absence_enabled` is true, so Dhaam joins the moment
  it is switched on.

**First question to settle:** is Dhaam an organisation *inside the same
CyberPulse installation* at `cyberpulse360.com`, or a separate deployment? If
separate, that host needs the endpoint deployed and checked on its own before
any of the above applies.

To find its `organizationId`: log in as somebody in that organisation and read
`Org ID is:` off the browser console (`services.js:6`), or ask the admin for
`db.organizations.find({}, { name: 1 })`.

---

## 0.9 2026-11-17 — the leave pull is paused, by decision

**Done today. Remember this, because nothing on screen will remind you.**

The two `hrms:pull` entries in `routes/console.php` are **commented out**. The
attendance pull is untouched and still runs.

**Why.** No company has `pull_email` / `pull_password` set, so both entries did
nothing but fail — roughly ninety-six times a day — each one writing
`last_pull_status: failed` with *"No CyberPulse credentials are configured for
this company."* The HRMS screen showed a permanent red. A warning light that is
always on is a warning light nobody reads, and that costs more than the polling
was worth while it cannot work.

**What this means day to day.** Approved leave does not reach MealBells at all.
A person on leave still has a meal ordered unless somebody enters a skip by
hand. Company admins and HR are doing that deliberately for now — agreed with
the user on 2026-11-17.

**The decision behind it.** Skips are MealBells' own table and its own idea, and
three of the four sources already work without CyberPulse: `self` and
`recurring` come from the employee's own app, `hr` from the daily screen. Only
`leave` and `wfh` depend on the HR system. So pausing the pull removes one
source out of four, not the feature.

**How to turn it back on.** Uncomment both blocks. Do it the moment either of
these arrives:

- a read-only CyberPulse login saved on the HRMS screen, **or**
- CyberPulse actually sending webhooks — in which case *delete* the two blocks
  rather than uncommenting them, because polling for what is already being
  pushed is work for nothing.

**The cost of leaving it paused is measurable, so measure it.** The attendance
shadow report already records who did not clock in: 16 present and 5 absent on
both 16 and 17 November. If somebody absent has no skip against their name, the
gap is visible there. After a week or two that report can answer whether manual
entry is keeping up, from evidence rather than from a feeling.

> On 16 Nov, 14 `hr` skips were entered by hand, all within sixteen minutes. On
> 17 Nov, **none were entered at all**. Either nobody was away, or somebody
> forgot and twenty-two meals were ordered when fewer were needed. That failure
> is silent — no alert fires for a skip nobody created.

---

# The rest of this file is from 2026-10-12 and earlier

## Where to start

**Branch: `main`.** Nothing is parked on a side branch in this repo, and
nothing is unpushed: `origin/main` and local `main` are both at `9be9185`.

> **Every hash below changed on 2026-11-11.** The history was rewritten with
> `git filter-repo` to normalise contributor identity. The hashes in this file
> have been repointed to their successors; anything quoted elsewhere from before
> that date will not resolve. See the note at the top of `HANDOVER.md`.

| | |
|---|---|
| Tests | **1228 green on SQLite** (4428 assertions) and **1228 green on PostgreSQL 18.6** (4424), both re-run 2026-11-11 |
| Skips | none |
| Pint | clean (`--dirty`, 2026-11-11) · `npm run build` clean (2026-11-11) · `npm run build:landing` not re-run, and no landing file has changed |

The migrate/rollback gate was **not** re-run, deliberately: no migration was
touched today, and that gate exists to catch a `down()` that works on one
driver and not the other.

Two builds now. `npm run build` does the application; `npm run build:landing`
does the Astro landing page into `public/landing`. Both CI jobs run both. If `/`
shows the sign-in page instead of the landing page, the second command has not
been run — that fallback is deliberate, not a bug.

Today's commits, newest first:

| | |
|---|---|
| `4a20409` | The landing page builds, and the stage renders |
| `0d793a7` | Landing dependencies install on a fresh clone |
| `b46b661` | Catch a CDN asset on the landing page, not a missing comment |
| `533e222` | A null `clocked_in` is unknown, not an absence |
| `fa8059f` | Astro for the landing page, and a public page at `/` |

Added 2026-10-13, after the push:

| | |
|---|---|
| `a9211ae` | Mark a range of calendar days at once (the deferred bulk UI) |
| `04c3174` | The default seeder says when it refuses |

Added since, and already on `origin/main`:

| | |
|---|---|
| `9be9185` | System overview, review findings and the CyberPulse work list |
| `def093b` | Day and night themes and a 3D meal-flow strip on the company dashboard |
| `aef307c` | Pin that attendance can never become a skip source |
| `880d19f` | Connecting attendance to a live HRMS, and one untested claim |

---

## The one decision waiting

> ## ✅ Merged 2026-11-12 — this section is now history
>
> Pushed to `sdt-cyberbells/Web_Cyberpulse` branch `rohit_works` and **merged
> into their `main`** the same day as PR #1. `main` is `d146d89`. Two commits:
> the endpoint, and `trust proxy` changed to `1` with the owner's approval.
> Verified on `main` after the merge — see
> [docs/system-overview.md](docs/system-overview.md) §7.0b.
>
> **Merged is not deployed.** Until their process restarts with
> `MEALBELLS_API_KEYS` set and a public https hostname, that endpoint answers
> nothing and our pull will fail. That, plus seeded attendance rows in their
> dev data, is what is left on their side.
>
> **Then MealBells needs only configuration**, no code:
> [docs/live-trial.md](docs/live-trial.md).
>
> One thing to carry into the trial: `trust proxy: 1` is still a **guess** at
> their hop count. It has to be checked on the deployed host, because if a
> second proxy sits in front, every caller collapses into one rate-limit
> bucket. §7.0a has the `curl` for it.
>
> The rest of this section is kept as it was written, as the background.

**The CyberPulse endpoint is committed locally and not pushed.**

`~/Desktop/Web_CyberPulse`, branch `feat/mealbells-integration`, commit
`0768520`. Three files, 282 insertions, additive only — a new controller, a new
route file, and two lines in `index.js`. Nothing existing was touched. Tested
end to end against the local `cyberpulse_test` database.

It was not pushed because that repo belongs to the CyberPulse team, and pushing
a branch into somebody else's repo is their call rather than ours. The code was
given in chat first, as asked.

**Correction to what I said on 2026-10-13.** I wrote that pushing it was
`git push -u origin feat/mealbells-integration`, "a second's work". It is not:
that clone has **no remote configured at all** — `git remote -v` is empty — so
there is nothing to push to and no way to fetch their repo either. I had not
checked, and the command I gave would have failed.

Two real options:

1. **Add the remote**, which needs the URL and access from their side:
   `git remote add origin <url>` then push the branch.
2. **Send the patch**, which needs nothing from us. Verified on 2026-10-27 to
   apply cleanly onto that clone's `main`:

```bash
cd ~/Desktop/Web_CyberPulse
git format-patch main..HEAD -o ~/Desktop/
# the dev applies it with:  git am 0001-*.patch
```

Their `.env` needs `MEALBELLS_API_KEYS=<key>:<organizationId>` and a restart —
dotenv reads the file once at startup, so a revoked key keeps working until the
process restarts. Worth telling them.

**Re-checked 2026-11-11, read-only, nothing changed.** The clone is now at
`~/Desktop/RED/Web_CyberPulse`. Branch `feat/mealbells-integration` is still one
commit ahead of its `main` and still has **no remote** — so the work exists on
that machine and nowhere else, which makes pushing or patching the most urgent
thing left on that side. The controller was read against the spec and meets it:
`X-API-Key` with a timing-safe compare, one row per active employee with
absences included, three-valued `clocked_in`, `moment-timezone` day bounds that
honour `tz`, no `.lean()` and no aggregation, `200` with an empty list instead
of `404`, and only the five agreed fields.

Two things that came out of that read and are not yet written down anywhere else:

- **`trust proxy: true` plus an IP-keyed rate limiter** (`backend/index.js:45`)
  means `X-Forwarded-For` can be forged, so the limiter on the new route can be
  bypassed. That route is unauthenticated by design, so the limiter is the only
  thing slowing a brute force against the API key. `'loopback'` or `1` fixes it.
- ~~**`docs/system-overview.md` §7.1 claims MealBells rejects a reply whose
  timezone is not the one it asked for.** It does not.~~ **Fixed 2026-11-11.**
  `CyberPulseClient::fetchAttendance` compared only `date`, so a vendor that
  ignored `tz` and cut the day in its own zone would have answered with the
  right-looking date and the wrong sixteen hours. It now refuses an echoed zone
  that is not the one it sent, tolerates the field being absent, and three
  tests cover it — the refusal one was mutation-checked by disabling the guard
  and watching it fail.

---

## Landing page — where it got to

The page builds and the WebGL stage renders a placeholder box. What is left is
design, and two concrete pieces:

1. **The beat divs.** `index.astro` has `<!-- beat divs yahan -->` inside
   `<section id="stage">`. That is where the scroll beats go.
2. **The lunchbox model.** `assets/Lunchbox.glb` is now tracked (committed
   2026-11-11) but still not wired up. Loading it means serving the file from
   `resources/landing/public` and adding a `GLTFLoader` call in
   `src/scripts/experience.js`. The question of whether the binary belonged in
   the repo was settled by committing it: at 9.5 KB for the `.glb` and 79 KB for
   the `.skp` source it is not worth the ceremony of keeping it elsewhere.

   Note that the 3D strip on the **company dashboard** (`def093b`) does not use
   this model at all — that scene is built procedurally in
   `resources/js/Pages/CompanyAdmin/Dashboard/mealFlowScene.js`. The `.glb` is
   for the landing page only, so do not go looking for where it is loaded.

Three constraints that will bite otherwise:

- **No CDN.** The page is served through Laravel, whose `script-src` is `'self'`
  and `'unsafe-inline'` only. Install with npm so it is bundled; a test fails if
  anything slips in.

  Correction to what I said earlier: that header goes out as
  `Content-Security-Policy-Report-Only` unless `SECURITY_CSP_ENFORCE=true`, so
  **today a CDN script would be reported, not blocked** — it would work, and the
  breakage would arrive later, on the day enforcement is switched on. Found by
  rehearsing the deploy. The rule still stands; the reason is "so enforcement
  can be turned on" rather than "it is blocked now".
- **No nested `package.json`** in `resources/landing`. Its dependencies would
  install into a gitignored `node_modules` and never reach a fresh clone or CI.
  `three` and `gsap` live in the root manifest. A test fails if one reappears.
- **No database.** It is static HTML built ahead of time. Anything dynamic needs
  an endpoint and a fetch.

The CSS added is structural only — sizing and position, no colours. The design
is still an open decision.

---

## Still open, in rough order

1. **Landing design** — the actual look, the beats, the model.
2. **Historical leave in the attendance report.** The report currently labels
   itself an upper bound for days before MealBells held any leave records,
   because anyone on approved leave then counts as absent. Bringing that history
   in needs a leaves adapter change, which is yours to specify.
3. **Staging deploy** — the code side is proven; it needs a host. Rehearsed on
   2026-10-13 against a fresh clone, `composer install --no-dev` and an empty
   PostgreSQL database in production mode: migrations, caches, both builds,
   smoke test and the `/health/ping` chain all pass. What is missing is a
   server, a database and a domain. Pick one of Laravel Cloud, a VPS or Forge
   and it can go up.

## Standing rules, still in force

- ~~Dev DB is not to be touched.~~ **Overridden 2026-11-13, by request**: the
  seed company `ACME01` was emptied and renamed `CYB01` so the real Cyberbells
  employees could replace it. See §0.3, which also has the backup path. The rule
  still holds for anything the user has not explicitly asked for — and the
  rest of it stands regardless: test on a copy; `php artisan serve` silently
  ignores a command-line `DB_DATABASE`, a direct `php artisan` command does not.
- Do not remove tests.
- Acme's demo secret stays as it is.
- The app's dark theme and colours are not to be changed.
- ~~`git add -A` is not to be used — `assets/` and this file are yours.~~
  **Lifted 2026-11-11**: both were committed on request. `git add -A` is still
  worth avoiding out of habit, but there is no longer anything in the tree that
  is deliberately being kept out of git.
