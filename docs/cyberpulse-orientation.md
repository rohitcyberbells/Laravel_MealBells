# CyberPulse — orientation

Notes on the HR system MealBells integrates with, for whoever has to work on
it. Written from reading the repository at `~/Desktop/Web_CyberPulse` on
2026-10-09, at `9f20360`.

> **This file lives in the MealBells repository on purpose.** The standing rule
> is that we do not edit or commit inside `Web_CyberPulse` — it is their live
> codebase. Reading it is fine and often necessary. If their team wants these
> notes, copy the file across rather than committing it from here.

**What was actually read:** `index.js`, `db.js`, the attendance model, route and
controller, the super-admin controller and route, the auth middleware, the
employee model, both `package.json` files, and the maintenance scripts.
Everything below about those is verified. The other twenty-odd controllers were
only listed, not read — so treat anything about chat, tasks, invoices or
payroll here as hearsay.

---

## 1. What it is

A people-management system for an IT company: attendance with clock-in and
clock-out, leave and WFH requests, tasks and projects, a chat with Socket.IO,
payroll, invoices, holidays, a handbook, performance ratings and push
notifications. In production at **cyberpulse360.com**.

| | |
|---|---|
| Backend | Node 22 + Express 4, ES modules (`"type": "module"`), Mongoose 8 on MongoDB |
| Frontend | React 18 on **Create React App** (`react-scripts` 5), Redux Toolkit, React Router 6, axios |
| Jobs | `node-cron` for four cron files, plus **Agenda** for scheduled messages |
| Realtime | Socket.IO |
| Auth | JWT, hand-rolled middleware |
| Scale | ~33 models, ~30 route files, ~11,700 lines of controller. `attendanceController.js` alone is **2,245 lines** |
| Tests | **None.** The only test file is CRA's stock `App.test.js` |

That last row matters more than anything else in the table. There is no safety
net, so any change you make is verified by running it and looking — which is
why the local harness in §3 is worth setting up before touching anything.

---

## 2. How it is organised

```
backend/
  index.js          every route mounted here, one app.use per feature
  db.js             a single mongoose.connect
  model/            33 Mongoose schemas
  route/            thin routers, mostly router.use(authenticateToken)
  controller/       all the logic
  middleware/       authMiddleware.js, rateLimitMiddleware.js
  cronJobs/         4 node-cron files, imported for side effects by index.js
  scripts/          one-off data fixes, run by hand
  uploads/          served statically — selfies and attachments live here
frontend/src/
  components/       large React components, several with `copy`/dated siblings
  features/         Redux slices
  services/         axios and socket wrappers
```

Conventions worth knowing before you add anything:

- **Controllers are flat and long.** There is no service layer; a controller
  function talks to Mongoose directly. Follow that rather than introducing a new
  pattern in one file.
- **Routes authenticate at the router level** — `routerX.use(authenticateToken)`
  near the top — so a new route in an existing file is protected by default.
  Check, though: not every router does it.
- **Files named `... copy 2.js` and `...-06-01-25.js` are kept in the tree.**
  `TaskController copy.js` is 614 lines and imported by nothing. When you are
  searching for where something happens, make sure you are in the live file.

---

## 3. Running it locally

There is already a local harness for this, built for MealBells' integration
work: `~/cyberpulse-local-seed/`. It gives you a throwaway Mongo with seeded
employees, leave requests, WFH credits and attendance, plus a helper to approve
and reject leave.

```bash
cd ~/cyberpulse-local-seed

# Mongo, with its own data directory
~/Downloads/mongodb-macos-x86_64-7.0.37/bin/mongod \
  --dbpath db --port 27017 --bind_ip 127.0.0.1 --logpath mongod.log --logappend --fork

node seed.js                 # organisation, employees, leave, WFH credits
source .mock.env             # TIME_ENCRYPTION_KEY
node seed-attendance.js      # 10 days of attendance

~/cyberpulse-local-seed/scenarios.sh list        # see the seeded leave
~/cyberpulse-local-seed/scenarios.sh approve <id>
```

Then the app itself:

```bash
cd ~/Desktop/Web_CyberPulse/backend && npm start      # nodemon, :4040
cd ~/Desktop/Web_CyberPulse/frontend && npm start     # CRA, :3000
```

`backend/.env` already exists on the office machine. The variables the code
reads:

```
MONGO_URL  PORT  NODE_ENV  JWT_SECRET  TIME_ENCRYPTION_KEY
CLIENT_URL  SOCKET_CORS_ORIGIN
EMAIL_USER  EMAIL_PASS
FIREBASE_SERVICE_ACCOUNT  FIREBASE_SERVICE_ACCOUNT_PATH
GOOGLE_MAPS_API_KEY  INVOICE_SECRET_KEY
```

**`TIME_ENCRYPTION_KEY` is not optional.** `utils/timeEncryption.js` throws at
import time without it, so the server will not boot. It must be 64 hex
characters — `openssl rand -hex 32`. **Use the same key the data was written
with**, or every stored clock-in becomes unreadable.

The seed points at `mongodb://127.0.0.1:27017/cyberpulse_test`, so set
`MONGO_URL` to that for local work and you cannot touch anything real.

---

## 4. Multi-tenancy and roles

Every meaningful document carries `organizationId`, and scoping is **by
convention in each controller**, not enforced by the schema or by middleware.
A query that forgets it reads across tenants. Assume nothing; check the
controller you are editing.

`employee.type` is a **number with no enum and no comment**, which is the single
most confusing thing in the codebase. From how it is used:

| | |
|---|---|
| `1` | admin. `registerWithOrganization` creates type 1. A type 1 with **no** `organizationId` is the platform super admin |
| `2` | ordinary employee. What the seeder creates |
| `3` | something departmental — `Employee.exists({ department, type: 3 })` gates department filtering in several places. Probably a manager or department head |

`status` is a **string**, `'1'` for active, and queries exclude the inactive with
`status: { $nin: ['0', 0] }` — guarding against both the string and the number,
which tells you the data has both.

Auth is a bearer JWT carrying `id`, `email`, `type`, `organizationId`. One
special case worth knowing: a super admin (type 1, no org) may pass
`x-org-id` as a **header** and the middleware will adopt it as their
organisation. So that header is a tenant switch, and anything relying on
`req.user.organizationId` is trusting it.

---

## 5. Attendance — the part MealBells cares about

`model/AttendanceModel.js` is the most sensitive document in the system. One
record per employee per day:

| | |
|---|---|
| `date` | a `Date` |
| `clockInTime`, `clockOutTime` | **strings holding an ISO instant, AES-256-CBC encrypted at rest** |
| `clockInSelfie`, `clockOutSelfie` | **photographs of people** |
| `clockInLocation` | **latitude, longitude and a resolved street address** |
| `isWFH` | they clocked in, from home |
| `isEmergency`, `emergencyReason` | free text |
| `breakTimings[]` | every break, with pauses |
| `Employeestatus` | `active` / `on break` / `clocked out` |
| `autoClockOut` | set by the job that closes forgotten sessions |

### The encryption, and the trap it sets

`clockInTime` and `clockOutTime` are encrypted by a `pre('save')` hook and
decrypted by a `post('init')` hook, with an `enc:` prefix.

**`post('init')` only runs when Mongoose hydrates a document.** It does not run
for `.lean()`, and it does not run for aggregation pipelines. Both return the
ciphertext.

This is not hypothetical — there are two live instances:

**`superAdminController.js`, `getOrgAttendance`** (line ~94) ends its query with
`.lean()`, so `GET /api/superadmin/organizations/:orgId/attendance` returns
`clockInTime: "enc:3f2a…"`. Anything consuming it cannot read the time.

**`attendanceController.js`** (line ~421) runs

```js
$sort:  { date: -1, clockInTime: -1 }
$group: { lastClockIn: { $first: "$clockInTime" }, … }
```

Sorting on ciphertext. And because the IV is random per write, the ordering is
effectively random — so `lastClockIn` is not reliably the last clock-in. Worth
raising with their team; it is their call, not ours.

If you write anything that reads these fields: use Mongoose documents, or call
`decryptTime()` from `utils/timeEncryption.js` explicitly.

### The timezone, and the trap *that* sets

Day boundaries are built like this, in several places:

```js
const startOfDay = new Date(parsedDate.setHours(0, 0, 0, 0));
```

`setHours` uses the **server's** timezone. On a UTC host, an Indian
organisation's "today" begins at 05:30 IST, so the first five and a half hours
of clock-ins fall on the previous day. `moment-timezone` is already a dependency
and is the obvious fix.

The maintenance script `scripts/dhaanOrgFixClockOutTime.js` hardcodes
`T13:30:00.000Z` with the comment "7:00 PM IST" — someone has already had to
correct this by hand for one organisation.

### Existing endpoints, and what is wrong with each

All under `/api/attendance`, the whole router behind `authenticateToken`.

| | |
|---|---|
| `GET /fetchAll` | **No date filter at all.** Every attendance record the organisation has ever had, selfie paths included. Thirty employees over a year is roughly 7,500 documents. Also answers **404** when there are none, which makes "nobody clocked in" indistinguishable from "endpoint gone" |
| `GET /currentEmpAttendance?date=` | **The closest thing to useful.** Org-scoped, date-filtered, joins employees with attendance and leave. Returns tasks, selfie URLs and locations as well |
| `GET /:employeeId?date=` | One employee. A bare single-segment `/:employeeId`, declared **before** four later routes. Nothing is shadowed today, because the later ones are all two segments or a different method — but a new single-segment `GET` added after it would never be reached |
| `GET /monthlyAttendence`, `/weeklyAttendance`, `/fetchMonthlyClockData`, `/monthly-summary` | Reports |
| `POST /salaryCalculate` | Payroll from attendance |
| `POST /add`, `PATCH /update/:id`, `DELETE /delete/:id` | Writes |

### What MealBells is asking for

A new endpoint, specified in
[cyberpulse-attendance-endpoint-spec.md](cyberpulse-attendance-endpoint-spec.md)
— written to hand over, with no MealBells internals in it. In short: one date,
one row per active employee including the absent ones, five fields, an API key
instead of an employee login, and `200` with an empty array rather than `404`.

Until it exists there is a mock of it in `~/cyberpulse-local-seed/` (see
`README-attendance-mock.md` there) running on **port 4141**, built to the spec
including the awkward parts, so MealBells could be developed against reality.
It is outside git deliberately and gets deleted when the real endpoint lands.

---

## 6. Things that look wrong

Found while reading. Not fixed — we do not touch this repository — and listed so
whoever does work on it is not surprised.

| | |
|---|---|
| `.lean()` and aggregation returning encrypted times | §5. Two live instances, one of them an endpoint |
| Day boundaries in the server's timezone | §5. Already corrected by hand for one organisation |
| `http://localhost:4040` hardcoded in the frontend | `components/Chat/MessageBubble.js`, twice, for attachment URLs — so chat attachments cannot work in production |
| Production host hardcoded in the frontend | `services/socketService.js` has `https://cyberpulse360.com` inline, so a local frontend talks to the live socket server |
| `404` for an empty result | `fetchAll` and `fetchAllAttendanceByDate`. An empty collection is a successful answer |
| `app.use(cors())` with no options | Every origin allowed, on an API that serves selfies and locations |
| `express.json({ limit: '500mb' })` | Half a gigabyte of JSON accepted on every endpoint |
| Dead files in the tree | `TaskController copy.js`, `AttendanceManagement copy 2.js`, several dated siblings. Easy to edit the wrong one |
| `employee.type` is an undocumented number | §4 |
| `x-org-id` as a tenant switch | §4. Only for super admins, but worth knowing it exists |
| Both `bcrypt` and `bcryptjs` are dependencies | Two implementations of the same thing |
| No tests | Nothing will tell you when you break something |

None of these is MealBells' to fix. The first two are the ones worth mentioning
to their team, because they produce wrong answers rather than merely being
untidy.

---

## 7. If you are changing something

- **Work against the local harness**, never the live database. §3.
- **Find the live file first.** Search results will include `copy` and dated
  siblings that nothing imports.
- **Check tenant scoping** in the function you are editing. Nothing enforces it
  for you.
- **If you touch a clock time**, know whether your query hydrates a document or
  not, and use `decryptTime()` if not.
- **If you touch a date boundary**, use `moment-timezone` with the
  organisation's zone, not `setHours`.
- **Route order matters** in `attendanceRoute.js`: a bare `GET /:employeeId` sits
  above four later declarations. They are safe today, being two segments or
  other methods, but a new single-segment `GET` below it would be unreachable —
  declare it above line 26.
- There are no tests, so verify by running it and looking. Say in your handover
  what you actually checked and what you did not.

---

## 8. How MealBells connects today

Two paths, both read-only against CyberPulse apart from one login.

**Leave and WFH** — `hrms:pull` signs in as a real CyberPulse employee
(`mealbells-sync@test.local` locally), reads `/api/leave/fetchAll`, keeps five
whitelisted fields, and turns approved leave into meal skips. Contract:
[hrms-event-contract.md](hrms-event-contract.md).

**Attendance** — `hrms:pull-attendance`, shadow mode only: it reads who had
clocked in by the cutoff, records a boolean, and reports how many meals would
not have been ordered. **It changes no meal count.** Design and phasing:
[attendance-design.md](attendance-design.md).

MealBells deliberately keeps almost nothing. For attendance it stores a single
boolean per employee per day — whether they had arrived by the cutoff — and
throws the arrival time away. No selfie, no coordinate, no clock time. If you
are extending the integration, hold that line: the less of this data crosses the
boundary, the less either system has to answer for.
