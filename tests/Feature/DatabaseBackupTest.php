<?php

namespace Tests\Feature;

use App\Console\Commands\BackupDatabase;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Snapshots, and saying so when there are none.
 *
 * There was no backup of any kind, which mattered most next to the actions
 * that cannot be undone. A backup that silently stopped is only discovered
 * when someone needs it, so "never run" and "failed" are reported here as
 * faults rather than as silence.
 */
class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    protected string $directory;

    protected string $sourcePath;

    protected User $root;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);

        $this->directory = storage_path('framework/testing/backups-'.uniqid());
        config()->set('backup.directory', $this->directory);

        // A file-based connection of its own, migrated, and backed up with
        // --connection. RefreshDatabase wraps the default connection in a
        // transaction and SQLite refuses to VACUUM inside one - which is a
        // harness problem, not a product one, since the command runs standalone
        // from the scheduler.
        $this->sourcePath = storage_path('framework/testing/source-'.uniqid().'.sqlite');
        touch($this->sourcePath);

        config()->set('database.connections.backup_source', [
            'driver' => 'sqlite',
            'database' => $this->sourcePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $this->artisan('migrate', ['--database' => 'backup_source', '--force' => true])->run();

        Cache::forget(BackupDatabase::STATUS_KEY);

        $this->root = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test',
            'password' => bcrypt('password-1'), 'role' => 'super_admin',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        @unlink($this->sourcePath);

        parent::tearDown();
    }

    protected function backup(array $options = [])
    {
        return $this->artisan('mealbells:backup', array_merge(['--connection' => 'backup_source'], $options));
    }

    /** @return array<int, string> */
    protected function files(): array
    {
        return glob($this->directory.'/mealbells-*') ?: [];
    }

    /** @return array<string, mixed> */
    protected function healthProps(): array
    {
        return $this->actingAs($this->root)->get('/super-admin/health')
            ->getOriginalContent()->getData()['page']['props'];
    }

    // ------------------------------------------------------------ taking one

    public function test_it_writes_a_snapshot_and_creates_the_directory(): void
    {
        $this->assertDirectoryDoesNotExist($this->directory);

        $this->backup()->assertSuccessful();

        $this->assertDirectoryExists($this->directory);
        $this->assertCount(1, $this->files());
    }

    /**
     * The point of a backup: the data is actually in it.
     */
    public function test_the_snapshot_contains_the_data(): void
    {
        DB::connection('backup_source')->table('companies')->insert([
            'name' => 'Restorable Corp', 'code' => 'REST01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->backup()->assertSuccessful();

        $file = $this->files()[0];

        // Opened as its own database and queried, rather than trusting the size.
        $restored = new \PDO('sqlite:'.$file);
        $count = (int) $restored->query("select count(*) from companies where code = 'REST01'")->fetchColumn();

        $this->assertEquals(1, $count, 'the snapshot does not contain the row');
    }

    public function test_a_second_run_does_not_overwrite_the_first(): void
    {
        $this->backup()->assertSuccessful();

        // The filename carries the second, so move the clock rather than wait.
        $this->travel(2)->seconds();

        $this->backup()->assertSuccessful();

        $this->assertCount(2, $this->files());
    }

    public function test_it_records_a_success(): void
    {
        $this->backup()->assertSuccessful();

        $status = Cache::get(BackupDatabase::STATUS_KEY);

        $this->assertTrue($status['ok']);
        $this->assertGreaterThan(1024, $status['bytes']);
        $this->assertNull($status['error']);
        $this->assertEquals('sqlite', $status['driver']);
    }

    /**
     * An empty dump is a failure that looks like success, which is the worst
     * kind of backup to have.
     */
    public function test_an_implausibly_small_snapshot_is_treated_as_a_failure(): void
    {
        config()->set('backup.minimum_bytes', 50 * 1024 * 1024);

        $this->backup()->assertFailed();

        $status = Cache::get(BackupDatabase::STATUS_KEY);

        $this->assertFalse($status['ok']);
        $this->assertStringContainsString('treating as failed', $status['error']);
    }

    public function test_a_failure_is_recorded_rather_than_thrown(): void
    {
        // A directory that cannot be created.
        config()->set('backup.directory', '/proc/mealbells-cannot-exist');

        $this->backup()->assertFailed();

        $this->assertFalse(Cache::get(BackupDatabase::STATUS_KEY)['ok']);
    }

    // -------------------------------------------------------------- retention

    public function test_old_snapshots_are_pruned_and_recent_ones_kept(): void
    {
        mkdir($this->directory, 0750, true);

        $old = $this->directory.'/mealbells-2026-01-01-000000.sqlite';
        $recent = $this->directory.'/mealbells-2026-10-06-000000.sqlite';
        file_put_contents($old, 'x');
        file_put_contents($recent, 'x');
        touch($old, now()->subDays(30)->getTimestamp());
        touch($recent, now()->subDays(2)->getTimestamp());

        $this->backup()->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
    }

    public function test_the_retention_window_is_configurable(): void
    {
        mkdir($this->directory, 0750, true);

        $file = $this->directory.'/mealbells-2026-10-05-000000.sqlite';
        file_put_contents($file, 'x');
        touch($file, now()->subDays(3)->getTimestamp());

        $this->backup(['--keep' => 1])->assertSuccessful();

        $this->assertFileDoesNotExist($file);
    }

    // ----------------------------------------------------------- the schedule

    public function test_it_runs_nightly_before_the_prune(): void
    {
        $events = collect(app(Schedule::class)->events());

        $backup = $events->first(fn (Event $e) => str_contains($e->command ?? '', 'mealbells:backup'));
        $prune = $events->first(fn (Event $e) => str_contains($e->command ?? '', 'prune-operational-data'));

        $this->assertNotNull($backup, 'the backup is not scheduled');
        $this->assertEquals('30 2 * * *', $backup->expression);

        // A snapshot must exist of whatever the prune is about to remove.
        $this->assertNotNull($prune);
        $this->assertEquals('15 3 * * *', $prune->expression);
    }

    // --------------------------------------------------------- the health page

    /**
     * Reported as a fault, not as absence.
     */
    public function test_never_having_run_is_shown_as_a_problem(): void
    {
        $backup = $this->healthProps()['backup'];

        $this->assertFalse($backup['ever_run']);
        $this->assertFalse($backup['ok']);
        $this->assertTrue($backup['is_stale']);
        $this->assertStringContainsString('No backup has ever been recorded', (string) $backup['error']);
    }

    public function test_a_recent_success_is_shown_as_healthy(): void
    {
        $this->backup()->run();

        $backup = $this->healthProps()['backup'];

        $this->assertTrue($backup['ever_run']);
        $this->assertTrue($backup['ok']);
        $this->assertFalse($backup['is_stale']);
        $this->assertGreaterThan(0, $backup['size_kb']);
    }

    public function test_a_backup_that_stopped_days_ago_is_shown_as_stale(): void
    {
        $this->backup()->run();

        $this->travel(3)->days();

        $backup = $this->healthProps()['backup'];

        $this->assertTrue($backup['ok'], 'the last one did succeed');
        $this->assertTrue($backup['is_stale'], 'but it is far too old to rely on');
        $this->assertGreaterThan(36, $backup['hours_ago']);
    }

    public function test_a_failure_is_shown_with_its_reason(): void
    {
        config()->set('backup.minimum_bytes', 50 * 1024 * 1024);

        $this->backup()->assertFailed();

        $backup = $this->healthProps()['backup'];

        $this->assertFalse($backup['ok']);
        $this->assertStringContainsString('treating as failed', $backup['error']);
    }

    public function test_the_page_renders_the_backup_state(): void
    {
        $page = file_get_contents(resource_path('js/Pages/SuperAdmin/Health.vue'));

        $this->assertStringContainsString('backup.ever_run', $page);
        $this->assertStringContainsString('backup.is_stale', $page);
        $this->assertStringContainsString('backup-restore.md', $page);
    }

    public function test_the_runbook_exists_and_covers_both_drivers(): void
    {
        $doc = file_get_contents(base_path('docs/backup-restore.md'));

        $this->assertStringContainsString('pg_restore', $doc);
        $this->assertStringContainsString('integrity_check', $doc);
        // The limits have to be stated, or someone relies on what it does not do.
        $this->assertStringContainsString('Off-host copies', $doc);
        $this->assertStringContainsString('Encryption', $doc);
    }
}
