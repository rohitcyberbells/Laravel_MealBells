# Next session — the landing page, and one decision waiting

_Rewritten 2026-10-12, end of day. Committed on 2026-11-11, by request — it had
been kept out of git until then._

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

> **Doing this tomorrow? Go straight to
> [docs/cyberpulse-push-plan.md](docs/cyberpulse-push-plan.md).** It is the
> ordered runbook: the two code fixes to settle first, the git steps, the three
> checks after `git fetch`, the traps, and what is still to do once it merges.
> The rest of this section is the background behind it.

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

- Dev DB is not to be touched. Test on a copy; `php artisan serve` silently
  ignores a command-line `DB_DATABASE`, a direct `php artisan` command does not.
- Do not remove tests.
- Acme's demo secret stays as it is.
- The app's dark theme and colours are not to be changed.
- ~~`git add -A` is not to be used — `assets/` and this file are yours.~~
  **Lifted 2026-11-11**: both were committed on request. `git add -A` is still
  worth avoiding out of habit, but there is no longer anything in the tree that
  is deliberately being kept out of git.
