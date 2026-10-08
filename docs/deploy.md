# Deploying MealBells

Written for whoever puts this on a server. For running a demo on a laptop see
[demo-day.md](demo-day.md).

Requirements: PHP 8.3+ (built on 8.4), PostgreSQL, a web server, and somewhere
to run two background processes.

---

## 1. Never run these in production

| | Why |
|---|---|
| `php artisan db:seed` / `--seeder=DemoSeeder` | Creates fake companies, employees and skips. Both seeders refuse outside `local` and `testing`, but do not rely on that — do not type it. |
| `php artisan hrms:simulate` | Posts a real signed event through the real pipeline, so it cancels a real employee's meal. Refused in production. Use `hrms:pull {company} --dry-run`. |
| `php artisan migrate:fresh` | Drops every table. There is no undo and no soft delete anywhere in this schema. |
| **Settings → HRMS → Send test event** | In production this is signed and built but deliberately *not* delivered, so it is safe — the screen says so. Outside production it does create a real skip. |

`APP_DEBUG=true` belongs in the same list: it serves stack traces, environment
values and database credentials to anyone who triggers an error.

---

## 2. Environment

```bash
cp .env.example .env          # already production-shaped
php artisan key:generate
```

`.env.example` carries production defaults. Fill in: `APP_URL`, the `DB_*`
block, the `MAIL_*` block, and review every commented `HRMS_*` / `MEALBELLS_*`
knob listed at the bottom — the defaults are the tested ones, so leaving them
commented is fine.

Four values that cause real harm if wrong:

| | Must be |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `SESSION_SECURE_COOKIE` | `true` (requires HTTPS) |
| `MAIL_MAILER` | a real transport — `log` sends nothing at all |

> `.env.local.example` is the development counterpart, and is what
> `composer run setup` copies. The two files declare the same variables; a test
> fails if they drift apart.

### Database

PostgreSQL. One migration branches on the driver to use a partial unique index
on Postgres, and the JSON columns suit `jsonb`. SQLite is for local work and the
test suite.

---

## 3. Deploy

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
rm -f public/hot                      # see §6

php artisan migrate --force           # --force: no prompt on a server

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

**`--no-dev` matters.** `laravel/boost` and `laravel/pail` are dev
dependencies, and boost injects a browser-logging script into every page. With
dev packages installed in production that script ships to real users.

**After `config:cache`, `env()` returns null outside config files.** Anything
reading `env()` directly at runtime stops working. This codebase only calls
`env()` inside `config/`, which is the rule to keep.

Restart the queue worker last, so it picks up the new code:

```bash
php artisan queue:restart
```

---

## 4. The two background processes

Nothing works without both. They are not optional.

### Scheduler — one cron entry

```cron
* * * * * cd /var/www/mealbells && php artisan schedule:run >> /dev/null 2>&1
```

Every minute, as the web user. It drives:

| Command | Cadence | What breaks without it |
|---|---|---|
| `mealbells:process-cutoff` | every minute | **The count never locks.** The vendor is never told. This is the one that matters most. |
| `hrms:pull --all` | every 15 min | Approved leave never reaches the count |
| `hrms:pull --all --before-cutoff` | every minute, acts once per window | Leave approved that morning misses today's count |
| `mealbells:generate-recurring-skips` | hourly | "Every Friday" rules stop producing skips |
| `hrms:reconcile` | every 5 min | A webhook event that failed is never retried |
| `mealbells:health-alerts` | every 5 min | **Nobody is emailed when anything breaks.** See §8 |
| `model:prune` | 03:00 | HRMS payloads are never redacted |
| `mealbells:backup` | 02:30 | **No backups at all.** Nothing here is recoverable without one |
| `mealbells:prune-operational-data` | 03:15 | Sessions, notifications and failed jobs grow without limit |

`process-cutoff` and `generate-recurring-skips` also write a heartbeat the
**Health page** reads; it flags the scheduler stale after 3 minutes. If that
page says stale, the cron entry is the first thing to check.

### Queue worker — supervisor

`QUEUE_CONNECTION=database` means **every notification is queued, so with no
worker nothing is ever delivered** — not even the in-app ones.

`/etc/supervisor/conf.d/mealbells-worker.conf`:

```ini
[program:mealbells-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/mealbells/artisan queue:work --sleep=3 --tries=3 --max-time=3600
directory=/var/www/mealbells
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/mealbells-worker.log
stopasgroup=true
killasgroup=true
```

```bash
supervisorctl reread && supervisorctl update
supervisorctl status mealbells-worker
```

`--max-time=3600` restarts the worker hourly, which releases memory and picks
up deployed code. `stopwaitsecs` must exceed the longest job so a restart does
not kill one mid-flight.

Failed jobs land in `failed_jobs` and the Health page counts them:

```bash
php artisan queue:failed
php artisan queue:retry all
```

---

## 5. The first super admin

```bash
php artisan mealbells:create-super-admin
```

It prompts for the address, name and password. For an unattended run:

```bash
php artisan mealbells:create-super-admin \
  --email=ops@yourcompany.com \
  --name="Platform Root" \
  --generate-password
```

`--generate-password` prints one once and requires a change on first sign-in,
so the printed value only has to survive being pasted into your password
manager. Prefer it over `--password=…`, which lands in shell history.

Running it again with an existing address changes nothing and says so, so it is
safe in a deploy script and cannot be used to take over a colleague's account.

> **Mail to this address must work.** A super admin's only recovery is a reset
> link sent there (§5a). Either verify mail delivery before you need it, or
> create a second super admin.

### 5a. Password recovery

`/forgot-password` sends a reset link to any account whose address can receive
mail. Two things to know:

- The response is identical whether or not the account exists, so it cannot be
  used to discover which addresses are registered.
- An employee with no address of their own carries a stand-in one ending in
  `.local`, which is never written to. Those accounts get no link; their HR team
  resets them from the Employees screen.

---

## 6. `public/hot`

Vite writes this file while `npm run dev` runs. **While it exists, every page
loads its assets from the dev server instead of the built files** — so on a
server, every screen is blank. It is gitignored, so it will not show in
`git status`, and stopping the dev server does not remove it.

`rm -f public/hot` is in the deploy steps for that reason. To check:

```bash
curl -s https://your-host/login | grep -oE '(src|href)="[^"]+\.(js|css)"' | head -3
```

`/build/assets/app-XXXX.js` is correct. Anything mentioning `:5173` means the
file is still there.

---

## 7. Rollback

```bash
git checkout <previous-tag>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
rm -f public/hot
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```

**Migrations are the hard part.** `php artisan migrate:rollback --step=1`
reverses the last batch, and every migration in this repo has a working
`down()`. But a rollback that drops a column drops its data with it, and
nothing here is soft-deleted.

So: **take a database dump before every deploy that includes a migration**, and
prefer rolling forward with a fix over rolling a schema change back.

```bash
php artisan mealbells:backup
```

See [backup-restore.md](backup-restore.md) for the restore steps, the retention
settings, and what the nightly backup does *not* cover (off-host copies and
encryption are both the operator's job).

> **One `down()` is known broken on SQLite.** Dropping `employees.user_id`
> fails because a unique index still references it. It does not affect
> PostgreSQL, but it means "every migration has a `down()`" is not the same as
> "every rollback works" — another reason to restore from a dump rather than
> roll a schema change back.

---

## 8. Health checks

| | |
|---|---|
| `GET /up` | Laravel's own endpoint, unchanged. It proves the app boots — it stays **200 with the database unreachable, the worker stopped and the scheduler dead**, so on its own it is green through every failure this application actually has. |
| `GET /health/ping?token=…` | The real checks, machine-readable. **Point the uptime monitor here.** |
| `/super-admin/health` | The same signals for a person, with detail: failed jobs by queue with their oldest entry, missing snapshots, HRMS events stuck or abandoned, per-company pull status, the last backup. Behind a login, so a monitor cannot read it. |

### `/health/ping`

```bash
php artisan mealbells:health-token      # prints a token and the full URL
```

Put the value in `.env` as `HEALTH_PING_TOKEN`, then rerun
`php artisan config:cache`. **Until the token is set the endpoint answers 404**
and is effectively off.

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  'https://your-host/health/ping?token=YOUR_TOKEN'       # expect 200
```

The token may be sent as `X-Health-Token` instead of a query parameter, which
keeps it out of access logs and is worth preferring if the monitor allows a
custom header.

**200** when every check passes, **503** when any fails. The body names which:

```json
{
  "status": "failing",
  "failing": ["queue_worker"],
  "checks": { "queue_worker": { "ok": false, "oldest_pending_job_minutes": 41 } }
}
```

| Check | Fails when |
|---|---|
| `database` | a `select 1` throws |
| `scheduler` | no heartbeat, or older than `HEALTH_SCHEDULER_STALE_AFTER_MINUTES` (3) |
| `queue_failures` | `failed_jobs` exceeds `HEALTH_FAILED_JOBS_THRESHOLD` (25) |
| `queue_worker` | a job has sat unreserved longer than `HEALTH_PENDING_JOB_STALE_AFTER_MINUTES` (10) |
| `hrms_pull` | a configured pull is stale or last errored — set `HEALTH_PULL_STALENESS_FAILS=false` to report without failing |

Two things to know about `queue_worker`. There is no worker heartbeat to read
with `QUEUE_CONNECTION=database`, so this is inferred from work left sitting —
and an **empty queue passes**, because it is no evidence either way. A monitor
must not page someone because nothing happened to be queued at 3am. The flip
side is that a worker which died with an empty queue is not noticed until
something is queued.

Wrong token and missing token both answer **404**, the same as any unknown path:
an endpoint that confirms its own existence and names the component that is
down, to an unauthenticated caller, is reconnaissance. So a 404 from the monitor
means the token is wrong, not that the route is missing.

The endpoint carries no session, so polling it every minute does not fill the
sessions table, and it is rate-limited to 60 requests a minute per address.

### Alert emails

`mealbells:health-alerts` runs every five minutes from the same cron entry and
emails the operators when a check fails — the five above plus **backups**,
which is alerted on but deliberately kept out of `/health/ping` (a backup 37
hours old is a real problem, not an outage, and paging an uptime monitor at 3am
for it trains people to ignore the monitor).

| | Default | |
|---|---|---|
| `HEALTH_ALERTS_ENABLED` | `true` | |
| `HEALTH_ALERT_RECIPIENTS` | empty | Comma-separated. Empty means **every active super admin**. Set it to send to an on-call or ticketing address instead. |
| `HEALTH_ALERT_REPEAT_AFTER_MINUTES` | 60 | The same unresolved issue is not mailed again inside this window. |

Each issue is deduped on its own, and **one message is sent when it clears** —
without that, the reader cannot tell a fixed problem from one nobody has looked
at. The recovery mail says plainly that nothing was made up for automatically:
the scheduler catching up does not retroactively lock yesterday's count.

```bash
php artisan mealbells:health-alerts --dry-run   # what would be sent, sending nothing
```

A dry run deliberately records nothing, so it cannot suppress the real alert.

**The alert mails need a working mailer and a running queue worker** — they are
queued like everything else. That is the one hole: a dead worker cannot mail
you to say the worker is dead. `/health/ping` and an external monitor are what
cover that case, which is why both exist.

---

## 9. Who gets emailed

Most notifications are in-app only, deliberately: an employee or an HR admin is
already in MealBells, and mailing them every skip would make the mail
worthless. Three go out by email, because the recipient is not looking at a
screen when they matter.

| | Goes to | When |
|---|---|---|
| Daily count | the vendor | the day locks — with every company they cook for that date broken out, and the total |
| **UPDATED** count | the vendor | a post-cutoff change, leading with the **new total**, not the delta |
| Cutoff failed | super admins | a company's cutoff errored; once per company per day |
| Health alerts | super admins, or `HEALTH_ALERT_RECIPIENTS` | see §8 |

Two things that decide whether these arrive at all:

- **`MAIL_FROM_ADDRESS` must be a domain you can send from.** Laravel's own
  default is `hello@example.com`; this app instead derives one from `APP_URL`
  when the variable is unset, but either way a from-address that fails SPF is
  dropped silently by the receiving side and looks like a working mailer from
  here. Send one real mail and confirm it lands, rather than that it sent.
- **A login with no address of its own carries a stand-in ending `.local`**,
  which is never written to. Those accounts get the in-app copy and no mail —
  so if a vendor says they are not getting the count, check their address
  first.

Vendor-facing mail carries counts and company names only: never an employee
name, code, email or skip reason. A test asserts it.

## 10. After the first deploy, check

- [ ] `/` redirects to the sign-in page, and the tab reads **MealBells**
- [ ] an error page shows no stack trace (`APP_DEBUG=false`)
- [ ] `curl -sI https://your-host/login` shows `Strict-Transport-Security`
- [ ] the session cookie carries `secure`
- [ ] sign in as the super admin and open `/super-admin/health`
- [ ] the scheduler is **not** stale (wait 2 minutes after the cron is in)
- [ ] `supervisorctl status mealbells-worker` is `RUNNING`
- [ ] no `:5173` in the page source
- [ ] create a company, provision one employee login, and confirm the mail
      arrives
- [ ] `HEALTH_PING_TOKEN` is set, `/health/ping?token=…` answers **200**, and
      the uptime monitor is pointed at it
- [ ] `php artisan mealbells:health-alerts --dry-run` names the right
      recipients, and one real alert mail has been seen arriving
- [ ] run `php artisan mealbells:backup` and confirm the Health page shows it
- [ ] set `BACKUP_DIRECTORY` outside the release folder, and add an off-host
      copy — see [backup-restore.md](backup-restore.md)
