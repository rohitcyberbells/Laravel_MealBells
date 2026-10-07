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
| `model:prune` | 03:00 | HRMS payloads are never redacted |

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

Both seeders refuse outside local, and there is no bootstrap command yet, so
this is a one-off on the server:

```bash
php artisan tinker --execute '
\App\Models\User::create([
    "name" => "Platform Root",
    "email" => "ops@yourcompany.com",
    "password" => \Illuminate\Support\Facades\Hash::make("<a long random password>"),
    "role" => "super_admin",
    "must_change_password" => true,
]);'
```

`must_change_password` forces a change on first sign-in, so the password above
only has to survive being typed once.

> **There is no forgot-password flow.** If this account loses its password the
> only recovery is database access. Create two super admins, or keep the
> credential somewhere you trust.

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
pg_dump -Fc mealbells > /backups/mealbells-$(date +%F-%H%M).dump
```

---

## 8. Health checks

| | |
|---|---|
| `GET /up` | Laravel's own endpoint. Point an uptime monitor here. It proves the app boots — it does **not** check the database, the queue or the scheduler. |
| `/super-admin/health` | The real signals: scheduler heartbeat, failed jobs, missing snapshots, HRMS events stuck or abandoned, per-company pull status. Behind a login, so a monitor cannot read it. |

**Nothing alerts.** The Health page has to be opened by a person. Until that
changes, someone needs to look at it daily — the failure that costs most (the
queue worker down, or a pull that stopped) looks exactly like a quiet day with
no leave.

---

## 9. After the first deploy, check

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
