# Live trial: attendance

Attendance shipped in shadow mode. It reads who clocked in, records it, and
reports how many meals would not have been ordered — it creates no skip and
changes no count. See [attendance-design.md](attendance-design.md) for why.

Everything here is a config change. **No code change is needed to go live**, with
one exception named at the end.

---

## Connecting MealBells to CyberPulse once it is live

### 1. What has to be set, and where

Nothing about the vendor is in the application's code or its `.env`. The address
and the key are per company, held encrypted in the database and set through the
UI, because two companies can use two different HRMS installations.

| Value | Where | Who sets it |
|---|---|---|
| `pull_base_url` | Settings → HRMS | Company admin |
| `attendance_api_key` | Settings → HRMS | Company admin |
| `attendance_absence_enabled` | Settings | Company admin |
| `timezone` | Settings — sent to the vendor as `tz` | Company admin |
| `cutoff_time` | Settings — the time a clock-in is judged against | Company admin |
| `attendance_source = integrated` | Employees | Company admin, per employee |

The base URL is shared with the leave pull. Setting it once serves both.

Only one employee field matters: an employee whose `attendance_source` is not
`integrated` is left out of the read entirely — not absent, not unknown, simply
not asked about. That is how you run a trial on ten people instead of the whole
company.

Four env knobs exist and the defaults are the tested ones. Leave them alone
unless there is a reason:

```
HRMS_CP_TIMEOUT=15                          # seconds
HRMS_CP_MAX_ABSENT_SHARE=0.4                # above this a day records nothing
HRMS_CP_ATTENDANCE_BEFORE_CUTOFF_MINUTES=5  # when the scheduled read happens
```

### 2. The base URL must be https

In production `SafeHrmsBaseUrl` rejects plain http, `localhost`, and private or
link-local addresses. The pull posts a password to this host for the leave
fetch, and a company admin supplies the address, so an unencrypted or internal
URL is refused rather than trusted.

So CyberPulse needs a public hostname with a valid certificate before this can
be switched on. `http://localhost:4040` works in local development and nowhere
else.

### 3. The first pull, by hand

Run it yourself before the scheduler ever does, on today only, **after 10:30
IST** — people need to have arrived for the numbers to mean anything, and a
company with an 11:00 cutoff is read at 10:55 by the scheduler.

```bash
# Changes nothing and writes nothing. Run this first.
php artisan hrms:pull-attendance ACME01 --date=$(date +%F) --dry-run

# Then for real. Still shadow mode: no skip, no count change.
php artisan hrms:pull-attendance ACME01 --date=$(date +%F)
```

Use the company **code**, not its id.

Do not pass an older date on the first run. A backfilled day counts anyone who
was on approved leave as absent, because MealBells held no leave record for that
date — the report says so and labels itself an upper bound, but it is a
confusing number to meet first.

### 4. What to look for

The command prints a table. Then open **Attendance (shadow)** in the company
admin menu for the same figures per day.

| Row | What it should say |
|---|---|
| Eligible employees | The number you set to `integrated`. If it is 0, nobody is switched on. |
| Not matched to an employee | Ideally 0. Anything here is a person CyberPulse knows and MealBells does not. |
| Unknown | Employees the HR system said nothing about. A large number means the trial group does not match the HRMS. |
| Absent by cutoff | Did not clock in by `cutoff_time`. |
| Already not eating | Leave, WFH, a recurring rule, or entered by hand. |
| **Would-be absent** | **The number the whole exercise exists to produce**: meals that would not have been ordered. |

Unmatched people are listed by their HRMS id — or, only when the row carried no
id at all, by the email it did carry. Never a name, because that list is shown
on a screen and stored in a summary column. Fix them by setting `external_id` or
the email on the matching employee. Until then they are treated as present, so
nobody loses a meal to a mismatch.

Let it run for a week or two before reading anything into the average. One day
is weather; ten days is a pattern.

### 5. What the fail-safe messages mean

All of these record **nothing** for the day. A day with no reading is left out
of the report's average rather than counted as zero, so an outage cannot look
like a day when nobody came in.

| Message | Meaning | What to do |
|---|---|---|
| `CyberPulse returned 401 for the attendance fetch.` | The API key is wrong, or revoked, or their process has not restarted since it changed. | Re-enter the key. Their server reads it once at startup, so they must restart. |
| `Could not reach CyberPulse for the attendance fetch.` | DNS, TLS, firewall or timeout. The message is deliberately ours — theirs can carry the request, and the request carries the key. | Check the host from the server itself. |
| `CyberPulse answered for <date> when asked about <date>.` | They replied about a different day. | Their day boundary is probably built from their server's clock rather than the organisation's timezone. |
| `CyberPulse returned no employees at all.` | An empty list. Not "nobody came in" — the HR system told us nothing. | Check the key maps to the right organisation. |
| `<N>% of eligible employees look absent, over the 40% limit.` | More than `HRMS_CP_MAX_ABSENT_SHARE` looked absent. | Almost always their end: mis-scoped, mid-restart, or a holiday they do not know about. Raise the limit only if you have proven the day is real. |
| `No attendance API key is configured for this company.` | The key is not set, or the base URL is missing — the key cannot be saved without one. | Set the base URL first, then the key. |
| `Attendance is not enabled for this company.` | `attendance_absence_enabled` is off. | Switch it on in Settings. |

An employee the HR system cannot answer for — a clock-in whose encrypted time
will not decrypt — is recorded as **unknown**, never absent. Unknown never
contributes to "would-be absent". A vendor bug must not cost somebody lunch.

### 6. The scheduler

**Already wired.** `routes/console.php` runs
`hrms:pull-attendance --all --before-cutoff` every minute, acting once per
company per day, 5 minutes before that company's cutoff.

It is a no-op until a company is switched on: `--all` selects only companies
whose `attendance_absence_enabled` is true. So there is nothing to add when
CyberPulse goes live — switching the company on is what starts it.

It runs after the leave pull on purpose. Leave should already be applied, so
anyone still unaccounted for is genuinely unaccounted for.

---

## The one thing that would need code

If CyberPulse serves the attendance endpoint from a **different host** than their
login API, one base URL cannot address both. `pull_base_url` is shared, so that
case needs a second column and a second field — a small change, but a change.

Worth asking them before the trial. Everything else here is configuration.
