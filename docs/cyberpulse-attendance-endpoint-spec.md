# Attendance endpoint — what we need from CyberPulse

Hi — thanks for suggesting the attendance approach. This is what we would need
on the CyberPulse side to make it work.

**What we are doing with it:** once a day, shortly before lunch is ordered, we
ask "for today, which employees had clocked in?" We use that to work out how
many meals to order. Nothing else.

**The short version:** one new endpoint, one date, one row per employee, five
fields, an API key. No employee login needed on our side.

---

## Why the existing endpoints do not quite work

Not a criticism — they were built for the dashboard, not for this.

| | |
|---|---|
| `GET /api/attendance/fetchAll` | No date filter, so it returns every attendance record the organisation has ever had — thousands of documents, including selfie paths. We would be downloading a year of data every day to answer a question about today. |
| `GET /api/attendance/currentEmpAttendance?date=…` | **Very close to what we need** — it already filters by date, scopes to the organisation, and joins employees with attendance. It returns a lot more than we should be given (tasks, selfie URLs, locations), and it needs an employee token. |

So the work is mostly trimming that second one and changing how it authenticates.

---

## The endpoint

```
GET /api/integration/attendance/daily?date=2026-10-09
```

Path name is up to you — anything stable is fine.

### Authentication

```
X-API-Key: <key>
```

A per-organisation API key, rather than an employee login. Two reasons: we do not
want to hold a real person's password, and a service account that appears in your
attendance reports as an employee is confusing for everyone.

Please make the key something you can rotate without a code change.

### Query parameters

| | |
|---|---|
| `date` | **Required.** `YYYY-MM-DD`. The day we are asking about. |
| `from` / `to` | Optional, and only if it is easy. Same format, maximum 31 days apart. We would use it to backfill, not day to day. |

### Rate limit

**60 requests per minute is plenty.** We call this once a day per organisation.
A limit is worth having anyway so a bug on our side cannot hammer your server.

---

## Response

```json
{
  "date": "2026-10-09",
  "timezone": "Asia/Kolkata",
  "generated_at": "2026-10-09T05:25:00Z",
  "employees": [
    {
      "employee_id": "66e0bb0000000000000000e2",
      "email": "acme001@demo.test",
      "clocked_in": true,
      "clock_in_at": "2026-10-09T05:01:00Z",
      "is_wfh": false
    },
    {
      "employee_id": "66e0bb0000000000000000e9",
      "email": "acme004@demo.test",
      "clocked_in": false,
      "clock_in_at": null,
      "is_wfh": false
    }
  ]
}
```

### The fields

| | |
|---|---|
| `employee_id` | Your employee id. This is what we match on. |
| `email` | A fallback for matching when we do not have the id yet. |
| `clocked_in` | Did this person clock in on this date. |
| `clock_in_at` | When, as an ISO timestamp with a timezone. We only use it to decide whether they were in **before our cutoff time**; we do not store it. |
| `is_wfh` | Whether the clock-in was marked work-from-home. We treat those people differently, so we need to tell them apart. |

### Two things that matter more than they look

**Please include every active employee, not only the ones with an attendance
record.** Someone who did not clock in has no attendance document, so if the
response only contains attendance records we cannot tell "this person was
absent" from "this person is missing from the response because something went
wrong". Those two need completely different handling at our end — one means do
not order a meal, the other means order one anyway to be safe.

So: one row per active employee, with `clocked_in: false` for those who did not
turn up.

**Please pin the day boundary to the organisation's timezone.** The existing code
builds the day like this:

```js
const startOfDay = new Date(parsedDate.setHours(0, 0, 0, 0));
```

`setHours` uses the **server's** local timezone. If the server runs in UTC, then
for an India-based office "today" starts at 05:30 IST and the first few hours of
clock-ins land on the wrong day. Either compute the boundary explicitly in
`Asia/Kolkata`, or accept a `tz` parameter and honour it. Echoing the timezone
back in the response (as above) lets us check we agree.

---

## Please do not include these

We would rather not receive them at all, so they cannot end up in our database
or our logs:

- `clockInSelfie`, `clockOutSelfie` — photographs of people
- `clockInLocation` — latitude, longitude, address
- `isEmergency`, `emergencyReason`
- `breakTimings`, `breakTime`, `workingDay`
- anything salary-related
- tasks
- `position`, `department`, `image`, `gender`

None of it helps us count meals, and holding it would make us responsible for
data we have no reason to have.

---

## One gotcha in your own code, in case it bites

`clockInTime` is encrypted at rest. It is decrypted by the Mongoose
`post('init')` hook in `AttendanceModel.js`, which runs when you load a
document — so `Attendance.find(...)` gives you a readable time.

**That hook does not run for `.lean()` queries or aggregation pipelines.** If the
new endpoint is written either of those ways — which would be the natural choice
for something that has to be fast — `clockInTime` comes back as
`enc:<iv>:<ciphertext>` and we would have no way to read it.

Worth checking: there is already an aggregation in `attendanceController.js`
(around the `$sort: { date: -1, clockInTime: -1 }` / `lastClockIn` block) that
sorts and groups on `clockInTime`. Since the hook does not run there, that is
sorting the encrypted string rather than the time — so it probably is not
returning what it looks like it returns. Separate from our request, but you may
want a look.

---

## Errors

| | |
|---|---|
| `401` | Missing or wrong API key |
| `400` | Missing or malformed `date` |
| `200` with `"employees": []` | Nobody clocked in, or there are no employees. **Please do not use `404` for this.** |

That last one is the only behaviour change we would really press for.
`attendance/fetchAll` currently answers `404` when there are no records, which
makes "nobody clocked in today" indistinguishable from "the endpoint is gone" or
"the key is wrong" — and we have to react to those very differently. An empty
list is a successful answer.

---

## Testing

If there is a way to get some attendance rows into the local/dev database, that
would help a lot — the local seed currently has employees and leave requests but
no attendance records, so we cannot try any of this end to end yet. A handful of
days with a mix of on-time, late, work-from-home and absent would be ideal.

---

Happy to adjust any of the naming — the shape is what matters to us, especially
the one-row-per-employee part and the timezone. If anything here is awkward on
your side, tell us and we will work around it rather than you bending the HR
system for us.
