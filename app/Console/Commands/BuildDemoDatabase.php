<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Builds a throwaway SQLite file holding nothing but demo data.
 *
 * The alternative - `migrate:fresh --seed` on the working database - destroys
 * whatever is in it, and on a developer's machine that is usually real: a
 * company with a live HRMS secret, hand-entered employees, a reproduction of
 * somebody's bug. Demo data is wanted often and is worth nothing, so it gets
 * its own file and the working database is never touched.
 *
 * The guard that matters is refuseIfInUse(): the command will not write to the
 * database the application is currently configured to use, whatever --path
 * says. Without it a single typo turns a demo build into data loss.
 */
class BuildDemoDatabase extends Command
{
    /** Written into every file this command builds, so a rebuild can tell its own work from real data. */
    protected const MARKER_TABLE = 'mealbells_demo_database';

    protected $signature = 'mealbells:demo-db
                            {--path= : Where to write it; defaults to database/demo.sqlite}
                            {--force : Rebuild from scratch if the file already exists}';

    protected $description = 'Build a separate SQLite database containing only demo data';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('mealbells:demo-db only runs in local or testing.');

            return self::FAILURE;
        }

        if (config('database.default') !== 'sqlite') {
            $this->error('This command builds a SQLite file, but the default connection is '.config('database.default').'.');

            return self::FAILURE;
        }

        $path = $this->targetPath();

        if (! $this->refuseIfInUse($path)) {
            return self::FAILURE;
        }

        if (file_exists($path) && ! $this->option('force')) {
            $this->warn("A demo database already exists at {$path}.");
            $this->line('Pass --force to rebuild it from scratch. Nothing was changed.');

            return self::SUCCESS;
        }

        if (! is_dir($directory = dirname($path))) {
            $this->error("The directory {$directory} does not exist.");

            return self::FAILURE;
        }

        return $this->build($path);
    }

    protected function targetPath(): string
    {
        $path = (string) ($this->option('path') ?: database_path('demo.sqlite'));

        // Compared against the live path below, so both sides have to be
        // absolute and symlink-free or the comparison can be fooled. The file
        // may not exist yet, so the directory is what gets resolved.
        $directory = realpath(dirname($path));

        return $directory === false ? $path : $directory.DIRECTORY_SEPARATOR.basename($path);
    }

    /**
     * Refuse to write to the database the application is currently using,
     * unless this command built it.
     *
     * The whole point of this command is that it cannot cost anybody their
     * data, and `migrate:fresh` on the working database would do exactly that.
     *
     * The exception is not a loophole, it is the normal case: pointing .env at
     * the demo file is how you demo it, and a guard without this refused to
     * rebuild the demo database as soon as anybody started using it - so the
     * command became unusable in the one configuration it exists to serve.
     * A file carrying our marker holds nothing but data this command generated.
     */
    protected function refuseIfInUse(string $path): bool
    {
        $configured = (string) config('database.connections.sqlite.database');
        $resolved = realpath($configured);

        if ($resolved === false || $resolved !== $path) {
            return true;
        }

        // Configured but absent: .env points at a file nobody has built yet,
        // which is the ordinary state on a fresh checkout. There is nothing
        // here to protect, and refusing would leave no way to create it.
        if (! file_exists($path)) {
            return true;
        }

        if ($this->isDemoDatabase($path)) {
            $this->warn('This is the database the application is currently using, and it is a demo database.');
            $this->line('Rebuilding it. Anybody signed in will be signed out.');
            $this->newLine();

            return true;
        }

        $this->error('Refusing: that is the database this application is configured to use.');
        $this->line('  Configured: '.$configured);
        $this->line('  Requested:  '.$path);
        $this->newLine();
        $this->line('It carries no demo marker, so it is treated as real data.');
        $this->line('Choose another --path. Demo data must never be built over a working database.');

        return false;
    }

    /**
     * Whether this file is one this command built.
     *
     * Anything that is not plainly ours - a real database, a half-written file,
     * something that is not SQLite at all - has to answer false, so the marker
     * is read rather than inferred from the filename.
     */
    protected function isDemoDatabase(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        try {
            // A non-SQLite file opens without complaint and fails on the first
            // read, so the query is what decides.
            $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $found = $pdo
                ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = '".self::MARKER_TABLE."'")
                ->fetchColumn();

            return $found !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Stamp the built file so a later run can recognise it as disposable.
     */
    protected function markAsDemo(): void
    {
        $connection = DB::connection('sqlite');

        $connection->statement('CREATE TABLE IF NOT EXISTS '.self::MARKER_TABLE.' (built_at text not null)');
        $connection->table(self::MARKER_TABLE)->insert(['built_at' => now()->toDateTimeString()]);
    }

    protected function build(string $path): int
    {
        $this->line("Building demo data in {$path}");

        if (file_exists($path)) {
            unlink($path);
        }

        if (touch($path) === false) {
            $this->error("Could not create {$path}.");

            return self::FAILURE;
        }

        /*
         * Point the sqlite connection at the new file, then put it back.
         *
         * purge() drops the already-resolved PDO, which would otherwise keep
         * writing to the old path. Restoring in a finally block matters more
         * than it looks: without it the connection stays aimed at the demo
         * file for the rest of the process, so anything running after this -
         * another command in the same call, a test - would quietly read and
         * write demo data while believing it was on the real database.
         */
        $previous = config('database.connections.sqlite.database');

        config(['database.connections.sqlite.database' => $path]);
        DB::purge('sqlite');

        try {
            $this->callSilent('migrate', ['--force' => true]);
            $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
            $this->markAsDemo();
        } finally {
            config(['database.connections.sqlite.database' => $previous]);
            DB::purge('sqlite');
        }

        $this->newLine();
        $this->line('To use it, set this in .env and restart the server:');
        $this->line("  DB_DATABASE={$path}");
        $this->newLine();
        $this->warn('`php artisan serve` ignores a DB_DATABASE set on the command line, so it has to go in .env.');
        $this->line('Remove the line to go back to your working database.');

        return self::SUCCESS;
    }
}
