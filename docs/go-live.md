# Going live

The order to do things in, once. Work down the page; do not skip ahead.

Everything here exists in more detail in [deploy.md](deploy.md) and
[backup-restore.md](backup-restore.md) — this is the spine, and it links out
rather than repeating. If you only read one page before a launch, read this one.

Set aside **half a day**, and do it on a day when you can watch the first
cutoff happen.

---

## Before you start

| | |
|---|---|
| A server | PHP 8.3+ (built on 8.4), PostgreSQL, a web server, somewhere to run two background processes |
| HTTPS | Not optional. `SESSION_SECURE_COOKIE=true` means the session cookie is never sent over plain http, so the app is unusable without a certificate |
| An SMTP account you have tested | The vendor's daily count and every password reset go through it |
| A domain | Needed for `APP_URL`, which the mails link back to |
| A place for backups **outside** the release folder | `/var/backups/mealbells` or similar |

> **Nothing in this application has run on a real server yet.** It has been
> exercised end to end on a laptop — PostgreSQL 18.6, production mode, built
> assets, a real queue worker, the scheduler — and the test suite passes against
> both drivers. But HTTPS, real SMTP, supervisor and a real cron have not been
> through a live deployment. Expect to find something at step 9 and leave time
> for it.

---

## 1. Code and dependencies

```bash
git clone … /var/www/mealbells && cd /var/www/mealbells
composer install --no-dev --optimize-autoloader
npm ci && npm run build
rm -f public/hot
```

**`--no-dev` matters.** `laravel/boost` and `laravel/pail` are dev
dependencies, and boost injects a browser-logging script into every page. With
dev packages installed, that script ships to real users.

**`rm -f public/hot` matters.** While that file exists every page loads its
assets from a Vite dev server that is not running, so **every screen is blank**.
It is gitignored, so it will not show in `git status`. See
[deploy.md §6](deploy.md).

---

## 2. Environment

```bash
cp .env.example .env          # already production-shaped
php artisan key:generate
```

**Never regenerate `APP_KEY` after go-live.** HRMS webhook secrets and pull
credentials are encrypted with it; a new key makes them unreadable.

Fill in `APP_URL`, the `DB_*` block and the `MAIL_*` block. Then check these
five by eye, because each one causes real harm if wrong:

| | Must be | If wrong |
|---|---|---|
| `APP_ENV` | `production` | demo seeders and `hrms:simulate` stop refusing |
| `APP_DEBUG` | `false` | stack traces, environment values and database credentials served to anyone who triggers an error |
| `SESSION_SECURE_COOKIE` | `true` | the session cookie travels over plain http |
| `MAIL_MAILER` | a real transport | `log` sends nothing at all |
| `MAIL_FROM_ADDRESS` | a domain you can send from | mail is dropped silently by the receiving side, and looks sent from here |

Every other `MEALBELLS_*`, `HRMS_*`, `HEALTH_*`, `BACKUP_*`, `SECURITY_*` and
`SENTRY_*` knob is listed in `.env.example` with its default. The defaults are
the tested ones, so leaving them commented is right.

> **`LOG_LEVEL=warning` silences `MAIL_MAILER=log` completely**, because the log
> mailer writes at debug. If you are testing mail by reading the log, lower the
> level first or you will conclude, wrongly, that nothing is being sent.

---

## 3. Database

```bash
php artisan migrate --force          # --force: no prompt on a server
```

PostgreSQL. One migration branches on the driver to use a partial unique index,
and the JSON columns suit `jsonb`. SQLite is for local work and the test suite.

**Do not run `php artisan db:seed`.** It creates a demo company and three
accounts whose password is `password`. Both seeders refuse outside `local` and
`testing`, and `db:seed` without `--force` refuses in production as well — but
do not type it.

---

## 4. Caches

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

**After `config:cache`, `env()` returns null outside config files.** This
codebase only calls `env()` inside `config/`, which is the rule to keep.

> **Never run the test suite on this server while a config cache exists.**
> `phpunit.xml` pins the suite to SQLite in memory through `<env>` entries, and
> those cannot override a cached config — the cached file wins, so the suite
> would point at **this database**, and `RefreshDatabase` is willing to
> `migrate:fresh` it. `TestCase` now refuses outright and tells you to run
> `php artisan config:clear`, but know why.

---

## 5. The two background processes

Nothing works without both. They are not optional.

### Cron — one entry

```cron
* * * * * cd /var/www/mealbells && php artisan schedule:run >> /dev/null 2>&1
```

Every minute, as the web user. It drives the cutoff lock, the HRMS pulls, the
recurring skips, the nightly backup, the prune and the health alerts — the full
table is in [deploy.md §4](deploy.md).

The one that matters most is `mealbells:process-cutoff`. Without it **the count
never locks and the vendor is never told how many meals to cook.**

### Queue worker — supervisor

`QUEUE_CONNECTION=database` means **every notification is queued, so with no
worker nothing is ever delivered** — not even the in-app ones. The supervisor
config is in [deploy.md §4](deploy.md).

```bash
supervisorctl reread && supervisorctl update
supervisorctl status mealbells-worker      # expect RUNNING
```

---

## 6. Backups

Nothing in MealBells is recoverable without one.

```bash
# in .env
BACKUP_DIRECTORY=/var/backups/mealbells
```

Outside the release folder, or a deploy that replaces it takes the backups with
it. The nightly run is already in the cron entry from step 5 (02:30, before the
03:15 prune). Take one by hand now and confirm it:

```bash
php artisan mealbells:backup
ls -lh /var/backups/mealbells/
```

**Then add the two things this command does not do**, both of them cron entries
of your own:

- an **off-host copy** — a snapshot that dies with the host is not a backup
- **encryption** — dumps are written in the clear and contain every employee's
  name and address

Both are spelled out in [backup-restore.md](backup-restore.md), along with the
restore procedure. **Read the restore section before you need it**, not during.

---

## 7. Health monitoring

```bash
php artisan mealbells:health-token
```

Put the printed value in `.env` as `HEALTH_PING_TOKEN`, rerun
`php artisan config:cache`, then point an uptime monitor at the URL it gives
you.

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  'https://your-host/health/ping?token=YOUR_TOKEN'       # expect 200
```

**Until the token is set the endpoint answers 404** and you have no monitoring.
`/up` is not a substitute: it stays green with the database down, the worker
stopped and the scheduler dead.

Then set who gets alerted:

```bash
# in .env, or leave empty to alert every active super admin
HEALTH_ALERT_RECIPIENTS=oncall@yourcompany.com
```

```bash
php artisan mealbells:health-alerts --dry-run    # names the recipients, sends nothing
```

The alerts are queued like everything else, **so a dead worker cannot mail you
to say the worker is dead.** That is precisely what the external monitor on
`/health/ping` is for, and why you need both.

---

## 8. The first super admin

```bash
php artisan mealbells:create-super-admin \
  --email=ops@yourcompany.com \
  --name="Platform Root" \
  --generate-password
```

`--generate-password` prints one once and requires a change on first sign-in, so
the printed value only has to survive being pasted into your password manager.
Prefer it over `--password=…`, which lands in shell history.

Running it again with an existing address changes nothing and says so, so it is
safe in a deploy script and cannot be used to take over a colleague's account.

> **Mail to this address must work.** A super admin's only recovery is a reset
> link sent there. Either verify delivery now, or create a second super admin.

---

## 9. Smoke test

In this order. Stop at the first failure.

```bash
curl -sI https://your-host/login | grep -i strict-transport      # HSTS present
curl -s https://your-host/login | grep -oE 'build/assets/[^"]+'  # built assets
curl -s https://your-host/login | grep -c 5173                   # expect 0
```

- [ ] `/` redirects to the sign-in page, and the tab reads **MealBells**
- [ ] an error page shows no stack trace
- [ ] the session cookie carries `secure`
- [ ] sign in as the super admin; `/super-admin/health` opens
- [ ] the scheduler is **not** stale (wait two minutes after the cron is in)
- [ ] `supervisorctl status mealbells-worker` is `RUNNING`
- [ ] `/health/ping?token=…` answers 200, and the monitor agrees
- [ ] create a company, pair it with a tiffin service, provision one employee
      login — **and confirm the mail arrives in a real inbox**
- [ ] `php artisan mealbells:backup`, then confirm the Health page shows it
- [ ] `php artisan mealbells:health-alerts --dry-run` names the right recipients

Then the one that proves the whole system, and the only one with a waiting step:

- [ ] set that company's cutoff a few minutes ahead, wait for it to pass, and
      watch the Daily Count page go to **LOCKED / auto-locked** — then check the
      vendor received the count by email

If that last one works, you are live.

---

## 10. Rollback

**Take a database dump before every deploy that includes a migration.** Every
migration has a working `down()`, verified on both drivers — but a rollback that
drops a column drops its data with it, and outside `companies` and
`tiffin_services` nothing here is soft-deleted.

```bash
php artisan mealbells:backup          # first, always

git checkout <previous-tag>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
rm -f public/hot
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan queue:restart
```

Prefer rolling **forward** with a fix over rolling a schema change back. If you
must unwind the schema, `php artisan migrate:rollback --step=1` reverses the
last batch — and restore from the dump rather than trusting the rollback to
preserve data it was never going to.

Restore steps: [backup-restore.md](backup-restore.md).

---

## Day two

| | |
|---|---|
| Watch the Health page daily for the first week | It is the only place that shows missing snapshots and stuck HRMS events |
| Confirm an alert mail actually arrives | Stop the worker for ten minutes and see whether you hear about it |
| Rehearse a restore once | Into a scratch database. An untested backup is a guess |
| Re-run `composer audit` and `npm audit` monthly | Two `league/commonmark` advisories are open with no fixed release yet; see [deploy.md](deploy.md) |

### Still not built, so do not promise it

- **Calendar has no bulk control** — holidays are declared one day at a time
- **The landing page is a redirect** — guests go straight to sign-in
- **Deleting a tiffin service or a company archives it**; nothing hard-deletes a
  tenant, but there is no purge either
