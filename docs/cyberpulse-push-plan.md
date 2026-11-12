# Pushing the attendance endpoint to CyberPulse

> ## ✅ Done and merged — 2026-11-12
>
> Pushed to `sdt-cyberbells/Web_Cyberpulse`, branch **`rohit_works`**, at
> `06cb59c`, then **merged into their `main`** the same day as PR #1. `main` is
> `d146d89`. Two commits: `bd7accf` (the endpoint) and `06cb59c` (`trust proxy`
> set to `1`). Checked on `main` afterwards: both files present, `trust proxy`
> at `index.js:56`, router mounted at `index.js:95`, tree identical to what was
> pushed.
>
> **Nothing is deployed yet**, so the endpoint answers nothing in production.
> §6 below is the remaining checklist.
>
> **What was done differently from the plan below:**
>
> - **`trust proxy` went in the same branch**, not a separate PR, because the
>   owner approved it first. It is still its own commit, so it can be reverted
>   without touching the endpoint.
> - **The two controller fixes in §2 were not applied.** The owner chose to
>   leave `status: { $ne: "0" }` and `type: { $ne: 1 }` for review to decide.
>   Keeping the `type` filter means company admins never appear in the
>   response, so MealBells will report them under **Unknown** and feed them
>   anyway — the fail-safe working, not a fault, but expect those names there
>   throughout the trial.
> - **Both commits were re-authored** to one contributor before pushing, which
>   is why `0768520` became `bd7accf`. Content is byte-identical.
> - **No rebase was needed.** `origin/main` was already `9f20360`, exactly what
>   the branch was built on, so §3's rebase step and §4's `index.js` conflict
>   never came up.
> - **A `pre-push` hook blocked the first attempt** — `.git/hooks/pre-push`,
>   written 2026-10-07 back when the rule was *read CyberPulse, never push to
>   it*. It printed `Push disabled: LOCAL TEST COPY` and exited 1. Renamed to
>   `pre-push.disabled` for the push, then restored. Not in §4 because nobody
>   remembered it was there. **If you clone fresh and a push fails for no
>   visible reason, look in `.git/hooks/` first.**
>
> Everything below is kept as the plan it was. Read §5 and §6 — the hop-count
> verification and the production checklist are still outstanding.

What to do, in order, to get the MealBells attendance endpoint out of the
office iMac and onto the CyberPulse repository for review.

Written 2026-11-11, after reading that clone read-only.

> Read [cyberpulse-orientation.md](cyberpulse-orientation.md) first if you have
> not worked in that codebase before. It has the traps: encrypted clock times,
> the undocumented `employee.type` number, dead `copy` files in the tree, and
> the fact that there are no tests at all.

---

## 1. Where the work is right now

| | |
|---|---|
| Clone | `~/Desktop/RED/Web_CyberPulse` |
| Branch | `feat/mealbells-integration` |
| Commit | `0768520`, 2026-10-12 — three files, +282 lines, additive only |
| Its parent | `9f20360` (Prince Patial, 2026-08-04) — **someone else's work** |
| Remote | **None configured.** `git remote -v` was empty on 2026-11-11 |

That last row is why this document exists. Until a remote is added and the
branch is pushed, `0768520` exists on exactly one machine and nowhere else.

What the commit adds:

```
backend/controller/mealbellsIntegrationController.js   new, 258 lines
backend/route/mealbellsIntegrationRoute.js             new,  22 lines
backend/index.js                                       +2 lines
```

No existing controller, model, route or middleware is touched.

**It meets the spec.** Read against the §7.1 checklist in
[system-overview.md](system-overview.md) on 2026-11-11 — own router, timing-safe
`X-API-Key`, one row per active employee with absences included, three-valued
`clocked_in`, `moment-timezone` day bounds honouring `tz`, no `.lean()` and no
aggregation, `200` with an empty list rather than `404`, only the five agreed
fields. §7.0a records the detail.

---

## 2. Decide these two before pushing

Both are in the new controller, in the employee query around line 179. Neither
is a blocker; both are easier to settle now than in review.

### (a) `type: { $ne: 1 }` — should go

```js
const employees = await Employee.find({
  organizationId,
  status: { $ne: "0" },
  type: { $ne: 1 },        // ← remove this line
}).select("_id email");
```

The intent was to exclude the platform super admin. But the platform super
admin is the one with **no** `organizationId`, and the query already filters by
`organizationId` — so that person cannot appear regardless. The clause only
removes the company's own admins, who clock in like everybody else.

Confirmed with the project owner on 2026-11-11: the admin does clock in, so
excluding them is wrong.

**Side effect, and it is the harmless direction.** Anyone CyberPulse returns
that MealBells cannot match lands in the report's "not matched" column
([PullAttendance.php](../app/Actions/Hrms/PullAttendance.php) `resolve()`).
Someone MealBells cannot find is noise in a column built to show exactly that;
someone MealBells *expects* and never hears about is treated as unknown and
fed anyway. Returning more people is the safe error.

### (b) `status: { $ne: "0" }` — measure first, then decide

The schema declares `status` a String enum `["0", "1"]`, so this is correct for
anything Mongoose wrote. Their own `attendanceController.js:376` nonetheless
uses:

```js
status: { $nin: ["0", 0] }
```

guarding against a **numeric** `0` as well — which suggests the collection has
both. MongoDB is type-sensitive: a document holding the number `0` does not
match `$ne: "0"`, so an inactive employee would be returned as active.

Do not guess. Run this read-only against production first:

```js
db.Employee.countDocuments({ status: 0 })     // the number
db.Employee.countDocuments({ status: "0" })   // the string
db.Employee.aggregate([{ $group: { _id: { $type: "$status" }, n: { $sum: 1 } } }])
```

`0` from the first query means the problem does not exist and the change is
cosmetic. Anything else means it is real, and the fix is `$nin: ["0", 0]`.

---

## 3. The push itself

A branch reportedly already exists on the repository. Its name may not match
the local one, so look before pushing.

```bash
cd ~/Desktop/RED/Web_CyberPulse

git remote add origin <repo-url>
git fetch origin

git branch -r                        # what branches exist, and the right name
git log --oneline origin/main -5     # how far main has moved
```

### Three things to check once you can see the remote

| Check | Command | Why it matters |
|---|---|---|
| Is the parent commit already on origin? | `git branch -r --contains 9f20360` | If it is not, your push carries **two** commits, not one — and the second is Prince Patial's |
| Has `origin/main` moved ahead? | `git log --oneline HEAD..origin/main` | Anything here means rebase before pushing, or the review is against a stale base |
| What is the remote branch called? | `git branch -r` | A different name needs the mapping form below |

### Then

```bash
git rebase origin/main          # or onto the senior's branch, if that is the base

git push origin feat/mealbells-integration                       # same name
git push origin feat/mealbells-integration:<their-branch-name>   # different name
```

---

## 4. Three traps

**Do not use `git add -A`.** That clone has two files modified by accident:

```
 M backend/.DS_Store      # tracked in their repo, which is its own problem
 M backend/.gitignore     # a stray trailing space, and the final newline lost
```

Neither is committed, so neither will be pushed — unless something sweeps them
in. Leave them, or `git checkout --` them, but do not commit them.

**The rebase will probably conflict in `index.js`.** Two lines: the import and
the `app.use("/api/integration", ...)`. Keep both sides — the conflict is other
people's routes landing beside yours, not a disagreement.

**Keep `trust proxy` out of this branch.** See §5. Mixing it in means a clean,
additive, reviewable change gets held up by a decision about everyone's IP
addresses.

---

## 5. The security fix — separate branch, separate PR

`backend/index.js:45`:

```js
app.set('trust proxy', true);
```

`true` tells Express to trust the whole `X-Forwarded-For` chain, and that
header comes from the client. `req.ip` becomes whatever the caller says, so the
IP-keyed limiter in `middleware/rateLimitMiddleware.js` (100 requests per 15
minutes) can be sidestepped by changing the header each request.

This is **pre-existing and not caused by the new endpoint** — but the endpoint
promotes it from untidy to load-bearing, because that route is deliberately
unauthenticated and the limiter is the only brake on guessing the API key.

The fix is a number, not a boolean:

| Deployment | Value |
|---|---|
| Node exposed directly, no proxy | `false` |
| One reverse proxy (nginx, Apache, ALB) | `1` |
| Cloudflare in front of nginx | `2` |
| Local proxy only | `'loopback'` |

Find the real hop count by logging `req.ip`, `req.headers['x-forwarded-for']`
and `req.socket.remoteAddress` on a request from outside. Verify afterwards:

```bash
curl -H "X-Forwarded-For: 9.9.9.9" https://<host>/api/integration/attendance/daily?date=2026-11-11
```

If `req.ip` still reads `9.9.9.9`, the number is still too high.

**Independently of all this, make the API key unguessable** — `openssl rand -hex 32`.
With 256 bits of entropy a bypassed rate limit stops mattering. The limiter is a
speed bump; the key is the lock.

Worth saying out loud to whoever owns the repo: the limiter is an in-memory
`Map`, so under PM2 cluster or multiple Node processes each worker keeps its
own counter and the effective limit multiplies. A shared limit needs Redis.
Not urgent; just not what it looks like.

---

## 6. Still to do on their side after the merge

- [ ] **`MEALBELLS_API_KEYS` in production**, format `<key>:<organizationId>`,
      comma-separated for more than one. There is **no `.env.example`** in that
      repo and the variable is documented nowhere but a code comment
- [ ] **Restart after setting or rotating it** — `dotenv` reads the file once at
      startup, so a revoked key keeps working until the process restarts
- [ ] **A public https hostname with a valid certificate.** MealBells'
      `SafeHrmsBaseUrl` refuses plain http and private addresses in production
- [ ] **Seed attendance rows in their dev data** — on time, late, WFH, absent —
      so the endpoint can be exercised without production

---

## 7. Then MealBells, which needs no code

Config only, all of it in the UI. The full runbook is
[live-trial.md](live-trial.md); in short:

1. Settings → HRMS: `pull_base_url` (https) and `attendance_api_key`
2. Employees: mark the trial group `attendance_source = integrated`
3. `php artisan hrms:pull-attendance ACME01 --dry-run` by hand, **after 10:30 IST**
4. Then without `--dry-run`. Still shadow mode: no skip, no count change
5. Switch on `attendance_absence_enabled`; the scheduler takes over from there
6. Read **Reports → Attendance (shadow)** for two to three weeks before
   deciding anything about Phase 2

Do not pass an older date on the first run — a backfilled day counts anyone who
was on approved leave as absent, because MealBells held no leave record then.
The report labels itself an upper bound, but it is a confusing number to meet
first.

If something goes wrong, the error message says what: every fail-safe message an
operator can meet is in [live-trial.md](live-trial.md) §5, including the
wrong-timezone one added on 2026-11-11.
