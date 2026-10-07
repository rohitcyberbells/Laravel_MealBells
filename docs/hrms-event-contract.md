# HRMS event contract

What MealBells accepts from an HR system, and what it does with it. Written for
whoever is wiring up a vendor — either sending us webhooks, or adding a pull
adapter.

A MealBells *skip* means "do not count this person's meal on this date". One
skip removes one whole day. Everything below exists to turn approved leave into
those rows, correctly and exactly once.

---

## 1. Endpoint

```
POST /api/hrms/{company_code}/events
Content-Type: application/json
```

`{company_code}` is the company's code in MealBells (`ACME01`), shown on the
company admin's HRMS screen along with the full URL.

One event per request. There is no batch form.

### Authentication

Per company, one of two modes.

**`signature` (preferred).** HMAC-SHA256 over `{timestamp}.{raw body}`, hex
encoded, using the shared secret.

```
X-Hrms-Signature: <hex hmac>
X-Hrms-Timestamp: <unix seconds>
```

The digest must be computed over the exact bytes sent. Re-serialising the JSON
before signing will not match. Compared with `hash_equals`, and the timestamp
must be within `tolerance_seconds` (default 300) of our clock, which is what
bounds replay.

**`token`.** `Authorization: Bearer <secret>`, for vendors that offer nothing
better. The secret travels on every request and nothing bounds replay, so it is
the fallback, not the default.

Header names and tolerance are configurable per company in `config/hrms.php`.

### Limits

| | |
|---|---|
| Max body | 256 KB (`hrms.max_body_bytes`) — checked **before** the signature, so an unauthenticated caller cannot make us hash arbitrary bytes |
| Rate limit | 300 requests/minute per company (`hrms.rate_limit_per_minute`) |

### Responses

| Status | Meaning | Retry? |
|---|---|---|
| `202` | Accepted and queued | No |
| `200` with `"duplicate": true` | Already held; nothing recorded twice | No |
| `422` | Missing `event_id` or `event_type`, or an unreadable body | No — fix the payload |
| `401` | Bad signature, bad token, or stale timestamp | No — fix credentials or clock |
| `413` | Body over the cap | No |
| `429` | Rate limited | Yes, with backoff |
| `5xx` | Our fault | Yes, with backoff |

A `202` means *recorded*, not *applied*. Applying happens on a queue; §6
describes the outcomes.

---

## 2. Envelope

The canonical shape. Field names are **not** fixed — `payload_map` in
`config/hrms.php` points at a vendor's own paths (dot notation), so onboarding
is usually config rather than code.

```json
{
  "event_id": "evt_01HX…",
  "event_type": "leave_approved",
  "occurred_at": "2026-10-06T09:15:00Z",
  "leave": {
    "id": "LV-4471",
    "employee_id": "HR-1024",
    "employee_email": "alice@acme.test",
    "from_date": "2026-10-08",
    "to_date": "2026-10-10",
    "type": "casual",
    "reason": "Family function"
  }
}
```

| Field | Required | Notes |
|---|---|---|
| `event_id` | **yes** | Unique per company, forever. This is the idempotency key — see §5 |
| `event_type` | **yes** | Must be a configured verb, see §3 |
| `occurred_at` | strongly recommended | When the decision was made in your system. Without it, out-of-order protection is lost for that event |
| `leave.id` | **yes** | Stable id of the leave *request*, not the event. Cancellations are matched on it |
| `leave.employee_id` | yes for approvals | See §4 |
| `leave.employee_email` | optional | Fallback key, see §4 |
| `leave.from_date` | yes for approvals | See §7 |
| `leave.to_date` | optional | Defaults to `from_date` |
| `leave.type` | sometimes | Needed only when `event_type` does not say leave vs WFH, see §3 |
| `leave.reason` | optional | Stored on the skip. Keep it short and avoid medical detail — it is visible to company admins |

Anything else you send is ignored. A cancellation needs only `event_id`,
`event_type`, `occurred_at` and `leave.id`.

---

## 3. Event vocabulary

Mapped by `event_type_defaults`, overridable per company:

| `event_type` | Action | Source |
|---|---|---|
| `leave_approved` | approve | `leave` |
| `wfh_approved` | approve | `wfh` |
| `leave_cancelled` | cancel | — |
| `wfh_cancelled` | cancel | — |

An `event_type` we have not been told how to read is **never guessed at** — it
is recorded `blocked`. Guessing either adds or removes someone's meal.

When an event name does not determine the source, `leave.type` is read through
`type_map` instead. That is the case for a vendor with one `leave.changed` event
for everything.

### WFH

WFH only produces a skip when the company has enabled "treat work-from-home as a
skip". Otherwise the event is recorded `ignored` — the same rule the CSV import
follows, so both channels behave identically.

### Partial days

`half-day` and `short-leave` are recorded `ignored` with
`partial_day_not_supported`. A skip is all-or-nothing, so a half day is neither
a skip nor a non-skip: cancelling the meal of someone who is in for lunch and
counting someone who is not are both wrong. The list is config
(`partial_day_defaults`, plus `partial_day_types` per company) — add your own
vocabulary there rather than mapping it to full-day leave.

---

## 4. Employee matching

Tried in order, **always scoped to the company in the URL**. A reference
belonging to another tenant cannot resolve.

1. `employees.external_id` = `leave.employee_id`
2. `employees.employee_code` = `leave.employee_id` (case-insensitive)
3. `employees.email` = `leave.employee_email`, or `leave.employee_id` when that
   is itself an address (case-insensitive)

Email is last because it is the weakest key: people change address and two
systems disagree about it. So **a match by email records your `employee_id` onto
that employee**, and every later event for that person resolves on step 1. That
write happens only when `external_id` is empty, so an id already recorded is
never repointed and a repeated event writes nothing.

No match is recorded `blocked` with `unknown_employee`, and surfaces on the
super admin's Health page. It is never retried — retrying cannot invent the
employee. Fix it by setting the employee's `external_id` or email in MealBells,
then re-send.

---

## 5. Idempotency and ordering

**`event_id` is the idempotency key.** `unique(company_id, external_event_id)`
means a re-delivery returns `200 duplicate` and is not applied twice. Retry
freely; do not invent a new id for a retry of the same event.

**Out-of-order delivery is handled.** If a newer event for the same `leave.id`
has already reached a decision, an older one arriving late is parked as `stale`
rather than applied — so an approval overtaking a cancellation does not
resurrect the skip. This relies on `occurred_at`; without it, last-write-wins.

**Re-sending an approval with a different range is how you edit a leave.** Days
it no longer covers are released, days it now covers are added. You do not need
to cancel first.

**Cancellation releases by `leave.id`**, matched against `skips.external_ref`.
This is the guarantee that matters to HR: a hand-entered skip has a null
`external_ref`, so **the HRMS can never cancel a skip a human created**.

---

## 6. Outcomes

Every event ends in one of these, visible on the Health page:

| Status | Meaning | Retried? |
|---|---|---|
| `applied` | At least one day reached the desired state | — |
| `ignored` | Deliberately not actioned: WFH disabled, partial day, or a range falling entirely on non-meal days | No |
| `blocked` | Permanently unactionable: unknown employee, unreadable payload, or every day refused by a guard | No |
| `stale` | Superseded by a newer decision on the same leave | No |
| `failed` | Transient (database unavailable, deadlock) | Yes, then a reconciliation backstop, then left for a human |

### Days are reported individually

An event covering five days where two are refused is `applied`, not failed. The
result records each day under `applied_days`, `already_days`, `blocked_days`,
`released_days`, `non_meal_days` or `outside_window_days`.

Days are dropped, with a reason, when they are:

- a weekend or a declared company holiday (`non_meal_days`)
- in the past, or beyond the advance limit (`outside_window_days`, 60 days)
- past the cutoff, or on a count already locked (`blocked_days`)
- already skipped (`already_days` — first source wins; the original source is
  kept)
- previously cancelled by a person (`blocked_days`, `blocked_cancelled` — an
  automated source must not resurrect what someone deliberately cancelled)

**Nothing silently disappears.** If a day you sent is not in `applied_days`, it
is in one of the others with a reason.

### Cutoff

Each company closes its count at a cutoff time. Leave approved after that for
*today* cannot change today's number — the kitchen has already been told. The
day is recorded `blocked`; the rest of the range still applies.

---

## 7. Dates

- `from_date` / `to_date`: `YYYY-MM-DD` is taken at face value.
- A value carrying a time is read in the vendor's timezone
  (`hrms.companies.{id}.timezone`, defaulting to the company's) and converted to
  the company's timezone before the date is taken.

That conversion matters. For a company in `Asia/Kolkata`, **both** of these mean
the 8th:

| Sent | Lands on |
|---|---|
| `2026-10-08T00:00:00.000Z` | `2026-10-08` |
| `2026-10-07T18:30:00.000Z` | `2026-10-08` |

If your system stores dates as UTC midnight, say so — the second row is what
IST-local midnight looks like in UTC, and reading it naively puts the leave a day
early.

`to_date` is **inclusive**. `to_date` before `from_date` is `blocked`.

---

## 8. Privacy

- Only the fields in §2 are read. Everything else in your body is ignored.
- The received payload is **redacted after 30 days**
  (`hrms.retention.payload_days`). The row survives — status, result and timings
  are the audit trail of what the integration did to someone's meals — but the
  raw PII does not.
- Do not send payroll, bank, salary, date of birth, address or document data. We
  do not need it and will not store it. A pull adapter must whitelist fields at
  the boundary and discard the rest before anything is logged or persisted.
- `leave.reason` is shown to company admins. Treat it as not-confidential.

---

## 9. Pull adapters

A vendor with no webhooks is integrated by pulling instead. A pull adapter
fetches, filters and shapes events, then hands them to the **same pipeline** —
it must not write skips itself. That is what keeps one set of guards, one audit
trail, one dedupe rule and one health view regardless of how the event arrived.

Requirements:

- A stable, deterministic `event_id` per state, so a re-run dedupes. Convention:
  `{vendor}:leave:{leave_id}:{state}`, e.g. `cp:leave:66f…:approved`.
- Whitelist fields at the boundary (§8).
- Filter to what matters — approved, still in the future — rather than replaying
  all history every run.
- Credentials encrypted at rest.
- Deleting a leave in the source usually leaves no trace to receive, so
  cancellation must be inferred by comparing a fetch against what was applied
  before. Treat that as dangerous: a failed or partial fetch looks exactly like
  "everything was cancelled", so cancel nothing unless the fetch clearly
  succeeded, and refuse an implausibly large cancellation batch rather than
  wiping a day's skips.

`CyberPulseAdapter` is the worked example.

---

## 10. Testing a connection

The company admin's HRMS screen sends a correctly signed test event through the
full middleware stack and shows the result, so credentials and signing can be
verified without the vendor.

From the CLI:

```bash
php artisan hrms:simulate --company=ACME01 --event=leave_approved --employee=HR-1024
php artisan hrms:simulate --company=ACME01 --event=leave_approved --employee=HR-1024 --print
```

`--print` shows the signed request without sending it, which is the quickest way
to check your own signing against ours. `--company` may be omitted when only one
company is configured.

---

## 11. Running a pull (`hrms:pull`)

For an HRMS that cannot push to us. CyberPulse is the one implemented.

### Setting the credentials

Easiest path — the company admin does it themselves:

**Settings → HRMS → Pull from your HR system.** Enter the HR system's URL
(https only), the login email and the password, pick the HR system, and Save.

The password is write-only. It is never sent back to the page; the form shows
only whether one is stored. Leaving the field blank on a later save keeps the
stored one, so changing the URL cannot silently clear it. Changing the URL,
email or password clears the cached token, because that token was issued for the
old identity or by the old host.

**The URL is checked on scheme and host, not just a prefix.** The server signs
in to whatever is entered, so without a host check the form would be a way to
probe the private network from inside it:

| | Production | Local / testing |
|---|---|---|
| `https://hrms.vendor.com` | allowed | allowed |
| `http://hrms.vendor.com` | refused — this sends a password | refused |
| `http://localhost:8901`, `http://127.0.0.1:8901` | refused | **allowed**, for a stub HRMS with no certificate |
| `10.x`, `172.16-31.x`, `192.168.x`, `127.x`, `169.254.x`, `0.0.0.0`, `::1`, `fc00::/7`, `fe80::/10` | refused | refused |

`169.254.169.254` is the one that matters most: on a cloud host that is the
instance metadata endpoint.

What is *not* checked is where a name resolves. A public hostname pointing at a
private address still passes, and DNS can be re-pointed after validation, so
that belongs at egress rather than in a form rule.

From the console instead:

```bash
php artisan tinker --execute '
$c = \App\Models\CompanyHrmsConnection::firstOrNew(["company_id" => 1]);
$c->fill([
    "pull_base_url" => "https://hrms.yourcompany.com",
    "pull_email"    => "integration@yourcompany.com",
    "pull_password" => "…",
    "pull_adapter"  => "cyberpulse",
])->save();
'
```

All four `pull_*` columns and the cached `pull_token` are encrypted at rest and
hidden from model serialisation.

### Checking it before it touches anything

**Test connection** on that screen fetches and reports what a real run would
apply, cancel, ignore and fail to match — and writes nothing.

When something did not match, it **names** the HR employee id and email behind
each one, because a count alone cannot be acted on. Fix those by setting
`external_id` (the HR system's own id) or the matching email on that employee in
MealBells, then test again — after which the pull resolves them on the strong
key by itself.

Same thing from the CLI, which prints the same list:

```bash
php artisan hrms:pull ACME01 --dry-run        # report only
php artisan hrms:pull ACME01 --dry-run -v     # per-leave detail
```

Point a new connection at `--dry-run` first. The counts it reports are the ones
a real run produces, including the "nothing to do" cases — a leave landing
entirely on a weekend or a declared holiday is reported as ignored by both.

Then, for real:

```bash
php artisan hrms:pull ACME01
```

Re-running is safe: each state has a stable event id, so a repeat applies
nothing and is reported as already seen.

### On a schedule

Two entries, both already registered:

| | |
|---|---|
| `hrms:pull --all` | every `hrms.cyberpulse.pull_every_minutes` (default 15) |
| `hrms:pull --all --before-cutoff` | every minute, but acts only inside the window before each company's cutoff, at most once per window |

The second exists because the regular cadence can leave a gap right before the
count locks, which is when leave approved that morning matters most.

Last run, counts and any warning appear on the super admin **Health** page. A
pull that stopped is flagged there, because otherwise it looks exactly like a
quiet day of no leave.

### What is not logged, and not stored

The fetch returns whole employee records. Eight fields survive the boundary —
leave `_id`, `startDate`, `endDate`, `leaveType`, `status`, and the employee's
`_id`, `email` and `name` — and the rest is discarded the moment the body is
parsed, before anything is logged, returned or persisted. So **bank details,
salary, date of birth, address, PAN, documents and photos never reach us at
all.**

Also deliberately absent:

- **The vendor's `reason` text.** It can carry medical detail and company admins
  can read skip reasons, so it is never kept. The skip gets a generated label
  such as `CyberPulse casual leave`.
- **The raw response.** It is never written to a log line, an exception message,
  a webhook payload or the database.
- **The credentials.** A login failure logs that it failed, not the request —
  the request body is a password.
- **`hrms_pull_runs`** holds counts, a status and warning strings only. No
  employee identifiers, no dates.
- **The unmatched-employee list.** Shown on screen for one render and printed by
  the command, never written to either stored record.

What *is* kept is the canonical envelope per event in `hrms_webhook_events`,
redacted after 30 days, and the run counts, deleted after 30 days.

### Cancellation, and why it is cautious

CyberPulse deletes a leave row outright rather than marking it cancelled, so
there is nothing to receive — absence from a later fetch is the only signal.
That makes a failed, truncated or mis-scoped fetch look exactly like a mass
withdrawal, and wiping a day of skips silently adds meals for people who are on
leave. Three things stand in front of it:

1. Nothing is cancelled unless the fetch clearly succeeded.
2. Only skips carrying this integration's own `cp:leave:` reference are ever
   touched. **A skip a person entered has no reference at all, so it cannot be
   seen, let alone released.**
3. A run proposing to cancel more than `max_cancel_share` (default 30%) of the
   leaves it holds cancels nothing and is marked `suspicious` on the Health page.
   A floor (`cancels_always_allowed`, default 3) keeps ordinary single
   cancellations working at small scale, where one withdrawal out of two held
   leaves is already 50%.

A withdrawn leave that is approved again is applied again: the integration may
restore a cancellation it made itself. It may not restore one a **person** made
— that decision stands.
