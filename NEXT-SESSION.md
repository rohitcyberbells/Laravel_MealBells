# Next session — plan

_Written 2026-10-06, after pushing today's work._

## Where things stand

- `origin/main` = `d4e36d4`. Nothing local, nothing uncommitted, no stashes.
- `release-candidate` points at the same commit, as a rollback ref. Delete it when
  it stops being useful: `git push origin --delete release-candidate`
- Suite: **463/463 green**, 1922 assertions. `pint --test` clean project-wide.
  `npm run build` clean.
- Today: 5 commits, 24 new tests.

| Commit | What |
|---|---|
| `183900e` | Super admin reset password — flash reached no render; plus the UI to trigger it |
| `081c039` | Company admin can reset an employee's password from the roster |
| `46eff3a` | Employee portal's Active/Paused button never worked (sent no state) |
| `acc3238` | HR can manage an employee's recurring skips from the roster |
| `d4e36d4` | Employee can skip a date range from the portal |

---

## 1. Browser pass on the four new screens — start here

**No bug is known in any of these.** They are unverified, not broken: the build
proves they compile, feature tests pin the server contract, and guard tests pin
the page and the server to the same keys. What nothing proves is that the
component mounts in a browser and that a click round-trips.

Run `composer run dev`, then:

- [ ] **`/super-admin/dashboard`** — a company card lists its admin accounts.
      Hit **Reset password** on one. Expect an amber panel with the temporary
      password and the admin's name/email. Reload: panel should be gone.
- [ ] **`/company-admin/employees`** — hit **🔄 Recurring** on a row. Add a rule,
      pause it, resume it, delete it. The button's count should only include
      rules that are live today.
- [ ] **`/company-admin/employees`** — hit **Reset password** on a row that has a
      login (rows without one show a checkbox instead). Expect the credentials
      panel, with a login ID that matches how that employee actually signs in
      (email if they have one, otherwise `ACME01 / ACME002`).
- [ ] **`/employee/dashboard`** — "Away for several days?" opens a From/To form.
      Pick a range covering a weekend; only the meal days should be skipped, and
      a weekend-only range should come back as an error, not a green banner.

**The one thing I'd check first.** In the recurring modal, `recurring_rules` is
an object keyed by employee id. Inertia serialises integer keys as JSON object
keys, so they arrive as strings, while `rulesFor(employeeId)` passes a number.
JS coerces the key, so `obj[5]` and `obj["5"]` are the same lookup and this
should be fine — and the server side is covered by a test. But if the modal
opens empty for an employee who definitely has rules, that is where to look.

If anything misbehaves, the browser console is readable from here via the
`browser-logs` tool — no need to copy anything out by hand.

---

## 2. Remaining audit findings

From the audit on 2026-10-06. Both still open; neither was in the four tasks.

- [ ] **Calendar bulk has no UI and no test.** `POST /company-admin/calendar/bulk`
      marks many dates holiday/working at once. The calendar page only does
      single-day post and delete. This is the weakest-supported endpoint in the
      app — no test either, so there is no proof it works. Worth writing the
      test before the UI.
- [ ] **The company dashboard computes a 7-day forecast and throws it away.**
      `CompanyAdminController` sends `todayStats` and `forecast`;
      `Dashboard.vue` declares neither. The loop runs `CalculateExpectedMeals`
      eight times on every dashboard load for nothing. Either surface it or stop
      computing it — right now it is both dead data and wasted queries.

---

## 3. Smaller things noticed and deliberately left alone

- [ ] **SuperAdmin dashboard: the HRMS secret panel is nested wrong.** It sits
      *inside* the `flex space-x-4 border-b` tabs row, so it renders as a flex
      item beside the three tab buttons. Pre-existing, unrelated to today's
      work, roughly a two-line fix. Today's password panel was deliberately
      placed outside that div.
- [ ] **No JS test runner in the project** (`package.json` has no vitest or
      playwright). Adding vitest would let the four components be mount-tested
      and would close the gap in section 1 permanently, instead of relying on a
      manual pass each time. It needs one dev dependency — your call.

---

## 4. Older open questions, still unanswered

These predate today and are decisions, not bugs:

- [ ] **Post-cutoff leave from HRMS** — what should happen when the HR system
      approves leave for a day whose cutoff has already closed? Today the engine
      refuses it, which is correct but silent.
- [ ] **What `attendance_source = 'integrated'` is supposed to mean** for an
      employee — whether it should block manual skips, and what it implies if
      the HR feed goes quiet.
- [ ] **`RestHrmsClient` and the command wiring have no `Http::fake()` coverage.**
      The reconciliation path is tested; the outbound client is not.

---

## Suggested order

1. Section 1 (browser pass) — cheap, and it either clears today's work or hands
   you a small fix list.
2. Fix whatever section 1 turns up.
3. Section 2, calendar bulk first (test, then UI), then the dead forecast.
4. Decide on vitest and the section 4 questions when you want to, not before.
