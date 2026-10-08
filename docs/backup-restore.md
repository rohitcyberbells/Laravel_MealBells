# Backup and restore

Written for whoever has to restore this, which is usually someone under
pressure. The restore steps come first for that reason.

Nothing in MealBells is recoverable without a backup. There are no soft deletes
outside `companies`, and `migrate:rollback` on a migration that dropped a column
drops its data with it.

---

## Restore

### 1. Find a backup

```bash
ls -lt /var/backups/mealbells/
```

Names are `mealbells-YYYY-MM-DD-HHMMSS.dump` (PostgreSQL) or `.sqlite`.

### 2. Stop anything writing

Otherwise the restore races the application and the queue.

```bash
php artisan down --render=errors::503
supervisorctl stop mealbells-worker
```

### 3. Keep what is there now

Even a damaged database is evidence. Restoring over it destroys the only copy of
whatever actually went wrong.

```bash
pg_dump -Fc mealbells > /var/backups/mealbells/pre-restore-$(date +%F-%H%M).dump
```

### 4. Restore

**PostgreSQL** — into a fresh database, then swap. A restore into the live one
leaves a half-restored database if it fails halfway.

```bash
createdb mealbells_restore
pg_restore --no-owner --no-acl -d mealbells_restore /var/backups/mealbells/mealbells-....dump

# check it before you commit to it (see §5), then:
psql -c 'ALTER DATABASE mealbells RENAME TO mealbells_broken;'
psql -c 'ALTER DATABASE mealbells_restore RENAME TO mealbells;'
```

**SQLite** — the file *is* the database.

```bash
cp database/database.sqlite database/database.sqlite.broken
cp /var/backups/mealbells/mealbells-....sqlite database/database.sqlite
chown www-data:www-data database/database.sqlite
```

### 5. Check it before trusting it

```bash
# SQLite
sqlite3 database/database.sqlite 'PRAGMA integrity_check;'      # expect: ok

# Either driver — row counts and one real record
php artisan tinker --execute '
echo "companies=".\App\Models\Company::count()
    ." employees=".\App\Models\Employee::count()
    ." skips=".\App\Models\Skip::count()
    ." counts=".\App\Models\MealCount::count()."\n";'
```

Then the check that matters more than counts — that the data is *usable*:

```bash
php artisan tinker --execute '
$u = \App\Models\User::where("role","super_admin")->first();
echo $u ? "super admin present: {$u->email}\n" : "NO SUPER ADMIN\n";
echo "locked counts: ".\App\Models\MealCount::whereNotNull("locked_at")->count()."\n";'
```

A restore with the right row counts but unreadable password hashes is still a
failed restore.

### 6. Bring it back up

```bash
php artisan migrate --force      # the backup may predate a deployed migration
php artisan config:cache
supervisorctl start mealbells-worker
php artisan up
```

Then open `/super-admin/health` and confirm the scheduler is not stale.

### 7. Afterwards

Keep `mealbells_broken` / `.sqlite.broken` until the cause is understood. Then
take a fresh backup, so the next restore does not start from before the
incident.

---

## How backups are taken

```bash
php artisan mealbells:backup
```

Scheduled daily at **02:30**, before the 03:15 prune, so a snapshot exists of
whatever the prune is about to remove. It runs from the one cron entry in
[deploy.md](deploy.md) — no separate cron is needed.

| | |
|---|---|
| PostgreSQL | `pg_dump --format=custom`, which `pg_restore` can read selectively |
| SQLite | `VACUUM INTO`, which asks SQLite for a consistent copy — `cp` on a live database can capture a half-written page |

The password is passed to `pg_dump` in the environment, not on the command
line, where it would show in the process list.

### Settings

| | Default | |
|---|---|---|
| `BACKUP_DIRECTORY` | `storage/backups` | **Change this.** Inside the app directory, a deploy that replaces the release folder takes the backups with it. Use `/var/backups/mealbells`. |
| `BACKUP_KEEP_DAYS` | 14 | Local copies pruned after this |
| `BACKUP_MINIMUM_BYTES` | 1024 | A dump smaller than this is treated as a failure — an empty dump is a failure that looks like success |
| `BACKUP_STALE_AFTER_HOURS` | 36 | When the health page calls the last backup stale |
| `BACKUP_PG_DUMP_PATH` | `pg_dump` | Set it if `pg_dump` is not on the web user's `PATH`, which is common |

### Health

`/super-admin/health` shows the last backup and flags three states as problems:
**never run**, **failed**, and **stale**. "No backup" is reported as a fault
rather than as silence, because a backup that stopped is otherwise discovered
only when someone needs it.

---

## What this does NOT do

Be clear about these before relying on it.

### Off-host copies

The command writes locally. A snapshot on the host that dies with the host is
not a backup. Add one of these as a cron on the server:

```bash
# object storage
aws s3 sync /var/backups/mealbells/ s3://your-bucket/mealbells/ --storage-class STANDARD_IA

# or another machine
rsync -az --delete /var/backups/mealbells/ backup-host:/srv/mealbells/
```

### Encryption

Dumps are written in the clear. They contain every employee's name and email
address, so on shared or third-party storage they should be encrypted:

```bash
gpg --encrypt --recipient ops@yourcompany.com /var/backups/mealbells/mealbells-....dump
```

Keep the private key somewhere other than the server being backed up —
otherwise losing the host loses the ability to read its backups.

### Point-in-time recovery

A nightly snapshot means up to 24 hours of loss. If that is too much, enable
PostgreSQL WAL archiving; this command is not a substitute for it.

### A restore you have not rehearsed

An untested backup is a guess. Restore into a scratch database once a quarter
and run the §5 checks.

---

## Verified

The SQLite path was exercised end to end on a copy of the development
database, not merely written down:

| | |
|---|---|
| Before | 2 companies, 40 employees, 17 skips, 12 users |
| Damage | all skips deleted, all of one company's employees deleted, one company row deleted → 1 / 0 / 0 |
| After restore | **2 / 40 / 17 / 12** |
| Checks | `integrity_check` → `ok`; a known employee row present; a password hash still verifying |

The PostgreSQL path has now been exercised too, on PostgreSQL 18.6:

| | |
|---|---|
| Backed up | `mealbells:backup` → an 84.9 KB custom-format dump |
| Restored | `pg_restore --no-owner --no-acl` into a fresh database, exit 0 |
| Checked | a known company and user present by name, and **the user's password hash still verifying** |

The hash check is the one that matters: a restore with the right row counts but
unreadable hashes is still a failed restore, and it looks like a successful one
from the outside until somebody tries to sign in.

Both were run against a scratch database, never the development one.
