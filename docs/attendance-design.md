# Attendance-based absence — design note

**Status:** design only. Nothing is built.

The CyberPulse developer suggested building the meal count from attendance
(clock-in) rather than from leave: *whoever clocked in gets a meal, whoever did
not does not.* This note is the plan we settled on after looking at what that
would actually do to the engine.

The short version: **Phase 1 measures, Phase 2 decides.** We pull attendance and
report what it *would* have changed, without changing a single count. Only if
the numbers justify it do we let attendance affect the count at all.

---

## Why not just switch the formula

Two reasons, and the second is the one that matters.

**Leave is known in advance; attendance is not.** Approved leave lets the count
and the week-ahead forecast be built before the cutoff. A clock-in is only known
on the day — and in our case only about thirty minutes before the cutoff, since
people clock in by around 10:30 and the cutoff is 11:00. That margin is enough
on a normal day and gone on a day with traffic, a flat phone or a slow HR
system.

**It changes what the product is.** Today MealBells says *tell us if you are not
eating*. Attendance-driven counts say *be on time or lose lunch*. Someone who
clocks in at 10:45 has no meal, and HR fixes it afterwards with an Extra — so
the work does not disappear, it moves from the employee to HR, and it moves from
"before the fact" to "while someone is hungry".

That is a people decision, not a technical one, and nobody should make it from a
guess about how many meals are being wasted. Hence Phase 1.

---

## Phase 1 — shadow mode

Pull attendance, record what we saw, and **report what we would have done**. The
count is untouched. No skip is created. Nothing the kitchen receives changes.

### What it produces

A report, per company per day:

| | |
|---|---|
| Eligible employees | the base the count already uses |
| Already not eating | leave, WFH, recurring, self, HR — the skips that exist |
| **Would-be absent** | clocked in by cutoff: no, and no other skip for that day |
| **Meals we would not have ordered** | the number that decides whether Phase 2 is worth anything |
| Not matched | employees we could not find in the HR system — see §(e) |
| Suspicious | the run tripped a safety guard — see §(c) |

Run it for **two to three weeks** before deciding anything. If the saving is a
meal or two a day, Phase 2 is not worth the risk it introduces. If it is
fifteen, we have a number to show HR when proposing a policy change — which is a
much better conversation than "the HR system can tell us who turned up".

### Where it stores what it saw

Its own table, not `skips`. In shadow mode there is no skip to create, and the
table is what Phase 2's report keeps using afterwards.

```
attendance_days
  company_id, employee_id, date
  clocked_in_by_cutoff   boolean
  is_wfh                 boolean
  source                 'cyberpulse'
  unique(employee_id, date)
```

**We store a boolean, not a timestamp.** We do not need to know when somebody
arrived, only whether they had by the cutoff. That decision is made once, by the
adapter, against the company's cutoff in its own timezone, and the arrival time
is thrown away. It keeps attendance-surveillance data out of MealBells
altogether, and it means a leaked MealBells database says nothing about anyone's
movements.

Everything else the HR system holds on an attendance record — selfie photos,
GPS latitude/longitude, the emergency reason, break timings — is never fetched
and never stored. The endpoint spec says so explicitly.

### Ordering

1. **Leave and WFH pull** — unchanged, every 15 minutes, plus the pre-cutoff run.
2. **Attendance pull** — about 5 minutes before each company's cutoff, once.

Leave first matters in Phase 2 and is harmless in Phase 1: someone on approved
leave should be attributed to leave, not to "did not clock in". WFH people do
clock in and the HR record flags them (`is_wfh`), so attendance can tell office
from home by itself — but their skip should still come from the WFH source,
because that is the reason a human would want to read.

---

## Phase 2 — only if Phase 1 justifies it

If we go ahead: **attendance becomes a new skip source**, not a replacement for
the formula.

```php
'canonical_skip_sources' => [..., 'attendance'],
'auto_skip_sources'      => [..., 'attendance'],
```

Both lists. `auto_skip_sources` matters: without it, attendance could
re-create a skip somebody had deliberately cancelled, overriding a human's
decision.

A skip source gets `unique(employee_id, date)` and first-source-wins for free,
which is exactly the arbitration that stops a person on leave being counted
absent as well. The two alternatives we considered are worse:

| | Why not |
|---|---|
| Snapshot at lock time, no skip rows | HR cannot see **who** was not counted or **why**. No audit, nothing in the employee portal, nothing in the adoption report. |
| Subtract `attendance_days` separately at count time | `CalculateExpectedMeals` would subtract skips **and** absences, so somebody on leave *and* absent is deducted twice. The unique index plus first-source-wins is precisely what prevents that; doing it this way means rewriting that arbitration. |

### Four things Phase 2 must change, which are easy to miss

**The breakdown is a hardcoded six-key array.** `CalculateExpectedMeals` returns
`breakdown` with exactly `leave, wfh, hr, self, link, recurring`. A seventh
source counts in `skip_count` but is absent from `breakdown`, so the breakdown
silently stops summing to the total. `MealCount.breakdown` is a stored JSON
column, so rows locked before the change will have no such key and the Daily
page will read `undefined`. Consumers: `CalculateExpectedMeals`,
`ConfirmDailyCount`, `PrepareDailyCountSummary`, `BuildAdoptionReport`,
`CompanyDailyController`, `Daily/Index.vue`.

**The anomaly detector will flag every single day.** `DetectCountAnomalies`
raises `spike` when `skip_count / base_eligible_count >= 0.30`. With absences
included, a perfectly normal day clears 30% easily — which means a `spike` flag
and an escalation email to the backup admin, daily, until somebody mutes it.
`count_deviation` fires at 20% and would also trip for the first week, while the
locked history is still leave-only. **The thresholds have to be retuned, or
absences excluded from the ratio, as part of the same change.** This is not a
follow-up.

**The leave pull does not operate on today at all.** In
`PullHrmsLeaves::approvedFutureLeaves()`:

> *"Leaves ending before tomorrow are dropped: today's count is at or past its
> cutoff"*

So a single-day leave for **today** is never applied by the pull, not even by
the pre-cutoff run. A multi-day leave starting today is, because its `to_date`
reaches tomorrow. This is good news for the design — attendance fills precisely
the gap the leave pull deliberately leaves, and the two do not compete on today.
It also means attendance is the *only* mechanism acting on today, so its
fail-safes carry the whole weight.

Related: `heldLeaveReferences()` filters `date >= tomorrow`, so the existing
cancellation detection will never reverse an attendance skip. **Do not add
`attendance` to its `whereIn('source', ['leave', 'wfh'])`** — leave-withdrawal
logic would start undoing attendance skips. A late clock-in is corrected through
`RecordPostCutoffChange` (the Extra path), which is the right route anyway: once
the count is locked, the number the kitchen was given must not change
retroactively.

**`RecordSkip` has a latent PostgreSQL bug that this feature makes likely.** Its
race fallback catches a unique-constraint `QueryException` *inside* a
`DB::transaction`. On PostgreSQL a failed INSERT aborts the whole transaction, so
the fallback query fails with `25P02`. Today it needs a genuine race to trigger.
With an attendance pull and a leave pull both able to write for the same employee
and date in the same minute near the cutoff, it stops being theoretical. **Fix
it before Phase 2**, the same way the webhook intake and the leave pull were
fixed in `a56d215`: nest the insert in its own transaction so the violation rolls
back a savepoint.

---

## Design decisions carried from the review

These are part of the design in both phases, not refinements to add later.

### (b) Per-employee opt-out uses a column that already exists

`employees.attendance_source` is already there, with values
`manual | integrated | none`, and currently means nothing. This feature gives it
a meaning: **attendance only applies to employees whose `attendance_source` is
`integrated`.** Anyone `manual` or `none` is left alone.

No new column, and the setting is where HR already edits the employee.

The company-level switch is a new flag on `company_settings`, default **off**,
following `wfh_auto_skip`. Both have to be on for anything to happen: the
company opted in, and this employee is integrated.

### (c) The safety thresholds are decided now, not later

The leave pull has a precedent — `HRMS_CP_MAX_CANCEL_SHARE=0.3` with
`HRMS_CP_CANCELS_ALWAYS_ALLOWED=3` — and attendance needs the same shape,
because the failure it guards against is far more likely:

| | |
|---|---|
| **If more than 40% of eligible employees look absent** | do nothing at all, mark the run suspicious, alert. Forty percent of a company is not on holiday; the HR system is more likely broken. |
| **If the fetch fails, times out, or returns no employees** | do nothing. Absence of data is not evidence of absence. |
| **If any single employee's record is unreadable** | treat that person as present and count them. |

Under-ordering means somebody goes without lunch; over-ordering means a wasted
meal. We choose the wasted meal every time.

In Phase 1 these guards govern whether a day appears in the report at all — a
suspicious day must not quietly contribute a large "would have saved" number
and flatter the case for Phase 2.

### (d) What the employee sees, and how they fix it

In Phase 2 an employee opens their portal and finds today marked skipped, with
the reason **"No clock-in by cutoff"**. If they did clock in and the HR system
missed it, they cannot undo it: `MealGuard::assertEditable` refuses any change
after the cutoff, correctly.

So the portal has to tell them what to do. Without that line they will work it
out by messaging HR anyway, and HR gets the same question every day with no
context.

The reasons a person reads must be the human ones: **"Leave"**, **"WFH"**,
**"No clock-in by cutoff"** — not a source code.

### (e) Unmatched employees must be visible

An employee with no `external_id` and no matching email cannot be found in the HR
system. They are treated as **present** — the fail-safe — and that is right.

But if half the company is not in CyberPulse (contractors, recent joiners),
then this feature is silently running on half the company and HR has no way to
know. So the unmatched count is surfaced in the report, on the Health page
beside the pull status, and in the pull summary, the way the leave pull already
reports `unknown_employee`.

A feature that works on an unknown fraction of the workforce is worse than one
that is switched off.

---

## Open questions

- **Is 11:00 the right cutoff for this?** Phase 1 will show how many people
  clock in between 10:55 and 11:00. If that number is not near zero, the margin
  is too thin and the cutoff should move before Phase 2, not after.
- **What happens on a day the HR system is down at 10:55?** Phase 1 answers
  nothing here because it changes nothing. Phase 2 needs an explicit answer:
  our choice is "order as if everyone is present", and that should be written on
  the Health page so the operator is not surprised by a high-count day.
- **Does anyone want the expected-vs-actual report for its own sake?** It may
  turn out to be the more valuable half, independently of counts.

---

## Effort

| | |
|---|---|
| Phase 1 | Medium. Client method, adapter method, pull action, command, one migration, one report screen or command output. Nothing in the engine. |
| Phase 2 | Medium, but it touches the engine: breakdown, anomaly thresholds, source vocabulary, the `RecordSkip` savepoint fix. |

Phase 1 is safe to build and cheap to abandon. That is the point of doing it
first.

### What the tests have to cover in Phase 1

Fetch failure, timeout and empty response all produce no report rather than a
report of zero; the 40% guard holds and is mutation-checked; an unreadable
record counts the person present; `attendance_source != 'integrated'` is left
out; an unmatched employee is counted present and reported as unmatched; a
holiday or weekend produces nothing; two runs on the same day are idempotent;
and the privacy sweep — no selfie path, no latitude, no longitude, no arrival
time anywhere in the database.

### And in Phase 2

Everything above, plus: a person on leave is not also counted absent; a WFH
clock-in is attributed to WFH; the breakdown sums to `skip_count`; a normal
attendance day does not raise `spike`; a late clock-in goes through the Extra
path and does not reverse the skip; the cutoff arriving mid-pull does not leave
half the company skipped; and the whole suite on PostgreSQL, because of the
savepoint fix.

---

## The endpoint

CyberPulse does not have a usable attendance endpoint yet.
`GET /api/attendance/fetchAll` takes no filter and returns every record the
organisation has ever had, selfie paths included.
`GET /api/attendance/currentEmpAttendance?date=…` is close to what we need and
shows the query is already written — it just returns far more than we should
receive.

What we are asking their developer to build is specified separately, in
[cyberpulse-attendance-endpoint-spec.md](cyberpulse-attendance-endpoint-spec.md),
written to be handed over without any MealBells internals in it.
