<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * A database snapshot, taken on a schedule.
 *
 * There was no backup of any kind, which mattered most next to the actions that
 * cannot be undone - and `migrate:rollback` on a migration that drops a column
 * drops its data with it.
 *
 * Two drivers, because production and development differ:
 *
 *   pgsql   pg_dump in custom format, which pg_restore can read selectively
 *   sqlite  VACUUM INTO, which asks SQLite itself for a consistent copy -
 *           copying the file with cp can capture a half-written page
 *
 * The outcome is recorded whether it succeeds or fails, so a backup that
 * silently stopped shows on the health page rather than being discovered when
 * someone needs it.
 */
class BackupDatabase extends Command
{
    public const STATUS_KEY = 'backup_last_run';

    protected $signature = 'mealbells:backup
                            {--connection= : Database connection to snapshot; defaults to the application default}
                            {--keep= : Days of backups to keep; defaults to config}';

    protected $description = 'Take a database snapshot and prune old ones';

    public function handle(): int
    {
        $started = now();
        $directory = (string) config('backup.directory');

        try {
            if (! is_dir($directory) && ! mkdir($directory, 0750, true)) {
                throw new \RuntimeException("Could not create {$directory}");
            }

            $connection = DB::connection($this->option('connection') ?: null);
            $driver = $connection->getDriverName();

            $path = match ($driver) {
                'sqlite' => $this->backupSqlite($connection, $directory, $started),
                'pgsql' => $this->backupPostgres($connection, $directory, $started),
                default => throw new \RuntimeException("No backup strategy for driver '{$driver}'."),
            };

            $bytes = filesize($path) ?: 0;

            // An empty or near-empty dump is a failure that looks like success,
            // which is the worst kind of backup to have.
            if ($bytes < (int) config('backup.minimum_bytes', 1024)) {
                throw new \RuntimeException("Backup at {$path} is only {$bytes} bytes; treating as failed.");
            }

            $pruned = $this->pruneOldBackups($directory);

            $this->record([
                'ok' => true,
                'at' => $started->toDateTimeString(),
                'path' => $path,
                'bytes' => $bytes,
                'driver' => $driver,
                'pruned' => $pruned,
                'error' => null,
            ]);

            $this->info("Backup written: {$path} (".number_format($bytes / 1024, 1).' KB)');

            if ($pruned > 0) {
                $this->line("Pruned {$pruned} backup(s) older than ".$this->keepDays().' days.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->record([
                'ok' => false,
                'at' => $started->toDateTimeString(),
                'path' => null,
                'bytes' => 0,
                'driver' => DB::connection($this->option('connection') ?: null)->getDriverName(),
                'pruned' => 0,
                'error' => $e->getMessage(),
            ]);

            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * VACUUM INTO, rather than copying the file: SQLite writes pages lazily, so
     * a plain copy of a live database can capture a half-written one.
     *
     * It cannot run inside a transaction - SQLite refuses - which is fine for a
     * scheduled command but means this is not callable from inside one.
     */
    protected function backupSqlite(ConnectionInterface $connection, string $directory, Carbon $at): string
    {
        $path = $directory.'/mealbells-'.$at->format('Y-m-d-His').'.sqlite';

        // VACUUM INTO refuses to overwrite, which is the behaviour we want.
        $connection->statement('VACUUM INTO '.$connection->getPdo()->quote($path));

        return $path;
    }

    protected function backupPostgres(ConnectionInterface $connection, string $directory, Carbon $at): string
    {
        $config = $connection->getConfig();
        $path = $directory.'/mealbells-'.$at->format('Y-m-d-His').'.dump';

        $process = new Process([
            config('backup.pg_dump_path', 'pg_dump'),
            '--format=custom',
            '--no-owner',
            '--no-acl',
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--username='.$config['username'],
            '--dbname='.$config['database'],
            '--file='.$path,
        ], timeout: (int) config('backup.timeout_seconds', 900));

        // Passed in the environment rather than the command line, where it
        // would be visible in the process list.
        $process->setEnv(['PGPASSWORD' => (string) $config['password']]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('pg_dump failed: '.trim($process->getErrorOutput()));
        }

        return $path;
    }

    protected function pruneOldBackups(string $directory): int
    {
        $cutoff = now()->subDays($this->keepDays())->getTimestamp();
        $pruned = 0;

        foreach (glob($directory.'/mealbells-*') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $pruned++;
            }
        }

        return $pruned;
    }

    protected function keepDays(): int
    {
        return (int) ($this->option('keep') ?: config('backup.keep_days', 14));
    }

    /**
     * @param  array<string, mixed>  $status
     */
    protected function record(array $status): void
    {
        // Kept for long enough that a backup which stopped a week ago is still
        // reported as stale rather than simply absent.
        Cache::put(self::STATUS_KEY, $status, now()->addDays(30));
    }
}
