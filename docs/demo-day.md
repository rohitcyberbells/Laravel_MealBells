# Demo day

Run through this before anyone is watching. It is short on purpose.

---

## 1. Assets: the one thing that can blank every page

`public/hot` is a marker Vite writes while `npm run dev` is running. **While it
exists, every page loads its JavaScript and CSS from the dev server instead of
the built files** — so if the dev server is not running, every screen is blank.

It is gitignored, so it will not show up in `git status`, and it does not get
removed when you stop the dev server with Ctrl-C.

### Before the demo

```bash
npm run build
rm -f public/hot
```

Then check it: the page must reference `/build/...`, not `:5173`.

```bash
curl -s http://127.0.0.1:8000/login | grep -oE '(src|href)="[^"]+\.(js|css)"' | head -3
```

- `…/build/assets/app-XXXX.js` → correct, built assets.
- `http://[::1]:5173/@vite/client` → **stop**, `public/hot` is still there.

### After the demo, back to development

```bash
npm run dev          # recreates public/hot and serves assets again
```

Or `composer run dev`, which runs the server, the queue and Vite together.

---

## 2. Starting the app for a demo

```bash
php artisan serve                 # http://127.0.0.1:8000
php artisan queue:work            # a second terminal — notifications
```

The queue worker only matters if you plan to show emails or notifications.
Nothing in the meal flow needs it: `QUEUE_CONNECTION=sync` runs jobs inline.

> **Careful:** `php artisan serve` does **not** pass a `DB_DATABASE` set on its
> command line through to the server process. If you intend to demo against a
> copy of the database, verify it before you trust it — rename a company in the
> copy and check the served page shows the new name. Otherwise use
> `PHP_CLI_SERVER_WORKERS=8 DB_DATABASE=/path/to/copy.sqlite php -S 127.0.0.1:8000 -t public router.php`
> with a router that returns `false` for files that exist, or static assets 404.

---

## 3. Fresh demo data

Demo data goes in a **separate file**, so building it can never cost you what
is in your working database:

```bash
php artisan mealbells:demo-db            # writes database/demo.sqlite
php artisan mealbells:demo-db --force    # rebuild it
```

Then point the app at it and restart:

```env
DB_DATABASE=/absolute/path/to/database/demo.sqlite
```

It has to go in `.env`, not on the command line — see the warning in section 2
about `php artisan serve`. Delete the line to go back to your own data.

The command refuses to write to any database it did not build itself, so
`--path` cannot be aimed at your working file by mistake. Rebuilding the demo
file while `.env` is pointed at it is fine — that is the normal case.

> **The old way, `migrate:fresh --seed --seeder=DemoSeeder`, still works and
> still destroys everything in the current database.** It is the right command
> only when you are certain the current database holds nothing you want.

`php artisan db:seed` also builds this same demo world. It used to build a
second, much thinner one of its own.

### What you get

**20 companies, 5 kitchens, 621 employees.**

Acme and Northwind are the two you walk a demo through — on one shared kitchen,
with skips from every source, a recurring rule, extra meals, a holiday, a
post-cutoff change, an emergency menu override on today, and an HR system
connected to one of them and not the other.

The other 18 companies and 3 kitchens are the denominator: they make the super
admin's directory, the health screen and the reports read as a platform rather
than a product with two customers. Headcounts vary (12 to 62) and cutoffs differ,
because every company having a round 40 and the same cutoff is the first thing
that reads as seeded data.

One kitchen is paired with nobody. Dates are derived from today, so it never
reads stale.

**All demo passwords are `demo1234`.**

| Role | Sign in with |
|---|---|
| Super admin | `root@mealbells.test` |
| Kitchen (Annapurna, paired) | `vendor@mealbells.test` |
| Kitchen (Tulsi Rasoi, unpaired) | `vendor.tulsi@mealbells.test` |
| Company admin (Acme) | `hr@acme.test` |
| Backup admin | `hr.backup@acme.test` |
| Employee (Acme) | `acme001@demo.test` |
| Company admin (Northwind) | `hr@nwnd.test` |

Everyone signs in with an email address and a password. There is no
employee-code route.

The other 18 companies are not in that table — 120 rows is not a list anybody
reads. Their logins follow the same pattern, and the seeder prints it:

| | |
|---|---|
| HR | `hr@<code>.test` — e.g. `hr@meri.test` |
| Employee | `<code>001@demo.test` — e.g. `meri001@demo.test` |

**Tulsi Rasoi is unpaired on purpose.** Sign in as it to show what a kitchen
sees on its first day, before MealBells has connected it to a company — and
pair it live from the super admin's screen, which is the decision that screen
exists for. Re-running the build puts it back.

Both the seeder and `mealbells:demo-db` refuse to run outside `local` and
`testing`.

---

## 4. The live cutoff lock

The most convincing thing to show, and the one with a waiting step. The count
locks when the scheduler runs **after** a company's cutoff time.

1. **Settings → Cutoff time:** set it 2–3 minutes ahead of now. Save.
2. Open **Daily Count** for today. It shows `DRAFT` and a countdown.
3. Start the scheduler and leave it running:

   ```bash
   php artisan schedule:work
   ```

4. Wait for the cutoff to pass. Within a minute of it, the page shows `LOCKED`
   and `auto-locked`, and the status becomes `auto_confirmed`.
5. Record a post-cutoff change (+3, "Late guests"). **Expected** stays at the
   locked snapshot while **Adjusted total** moves — that is the point: the
   number the kitchen was given does not change retroactively.

To lock without waiting, run the one command the scheduler would:

```bash
php artisan mealbells:process-cutoff
```

`schedule:work` also runs the HRMS pull every 15 minutes and a pre-cutoff pull
every minute, so starting it is enough for the HRMS part too.

---

## 5. HRMS, if you are showing it

The webhook side needs nothing external:

- **Settings → HRMS → Send test event** posts a correctly signed event to our
  own endpoint and shows what it did.

The pull side needs the CyberPulse backend on `localhost:4040`:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:4040/api/employee/login \
  -X POST -H 'Content-Type: application/json' \
  -d '{"email":"mealbells-sync@test.local","password":"password123"}'
```

`200` means it is up. Then, in **Settings → HRMS → Pull from your HR system**:

| Field | Value |
|---|---|
| URL | `http://localhost:4040` |
| Login email | `mealbells-sync@test.local` |
| Password | `password123` |

Save, then **Test connection** — it fetches and reports what a real run would
do, without writing anything.

> `http://localhost` is accepted only because `APP_ENV` is `local`. In
> production the URL must be https and must not point at a private address.

Change leave on the CyberPulse side with:

```bash
~/cyberpulse-local-seed/scenarios.sh list
~/cyberpulse-local-seed/scenarios.sh approve <id>
~/cyberpulse-local-seed/scenarios.sh reject <id>
~/cyberpulse-local-seed/scenarios.sh delete <id>
```

Then pull:

```bash
php artisan hrms:pull ACME01 --dry-run    # reports, writes nothing
php artisan hrms:pull ACME01              # applies
```

The dry run's numbers are the real run's numbers. A good sequence is: approve a
leave → pull → show the skip on **Daily Count** → reject it → pull → show the
skip released, while a skip someone entered by hand on the same day stays.

---

## 6. Known rough edges

Mention them before someone else spots them.

| | |
|---|---|
| **Calendar has no bulk control** | The endpoint exists and is tested; there is no UI yet, so holidays are declared one day at a time. Avoid promising a bulk flow. |
| **Adoption Report** | Thin with seeded data — only a few days of history exist. |
| **Vendor "tomorrow"** | Use the date picker on **Preparation**; there is no next-day shortcut. |

---

## 7. Two-minute pre-flight

```bash
npm run build && npm run build:landing && rm -f public/hot
php artisan migrate:fresh --seed --seeder=DemoSeeder
php artisan serve
```

Then in a browser:

- [ ] `/` shows the landing page, or the sign-in page if the landing build was skipped
- [ ] the browser tab reads **MealBells**, not "Laravel"
- [ ] sign in as `hr@acme.test` and open all seven sidebar links
- [ ] **Dashboard** shows today's count and the week-ahead strip
- [ ] **Daily Count** countdown reads `HH:MM:SS`
- [ ] tick two employees → **Create logins** shows temporary passwords
- [ ] **Employees → Bulk CSV Import** previews and confirms a small file

If the last one errors, you are on an old build — `git log` and check
`f5f7950` is in.
