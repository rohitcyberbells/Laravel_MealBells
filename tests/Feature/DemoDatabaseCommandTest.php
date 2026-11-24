<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

/**
 * `mealbells:demo-db` exists so that wanting demo data never costs anybody the
 * contents of their working database. Every test here is about that promise:
 * the refusals are the feature, and the build is the control that keeps them
 * from passing against a command that does nothing at all.
 */
class DemoDatabaseCommandTest extends TestCase
{
    protected string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = storage_path('framework/testing/demo-db-'.uniqid());
        File::ensureDirectoryExists($this->workspace);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    /**
     * The guard the command exists for.
     *
     * `migrate:fresh` on the configured database is exactly the accident this
     * prevents, so being pointed at it has to fail before anything is written.
     */
    public function test_it_refuses_to_build_over_the_database_in_use(): void
    {
        $live = $this->workspace.'/live.sqlite';
        file_put_contents($live, '');
        config(['database.connections.sqlite.database' => $live]);

        $this->artisan('mealbells:demo-db', ['--path' => $live, '--force' => true])
            ->expectsOutputToContain('It carries no demo marker, so it is treated as real data.')
            ->assertExitCode(1);

        $this->assertSame('', file_get_contents($live), 'the configured database was written to');
    }

    /**
     * The same refusal has to survive a path that points at the configured
     * database by a different spelling, because that is how the accident
     * actually happens - a relative path typed from a different directory.
     */
    public function test_the_refusal_is_not_fooled_by_an_indirect_path(): void
    {
        $live = $this->workspace.'/live.sqlite';
        file_put_contents($live, '');
        config(['database.connections.sqlite.database' => $live]);

        $indirect = $this->workspace.'/./nested/../live.sqlite';
        File::ensureDirectoryExists($this->workspace.'/nested');

        $this->artisan('mealbells:demo-db', ['--path' => $indirect, '--force' => true])
            ->expectsOutputToContain('Refusing: that is the database this application is configured to use.')
            ->assertExitCode(1);

        $this->assertSame('', file_get_contents($live), 'the configured database was written to');
    }

    public function test_it_refuses_outside_local_and_testing(): void
    {
        app()['env'] = 'production';

        $target = $this->workspace.'/demo.sqlite';

        $this->artisan('mealbells:demo-db', ['--path' => $target])
            ->expectsOutputToContain('mealbells:demo-db only runs in local or testing.')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($target);
    }

    /**
     * Rebuilding is destructive to the demo file, so it is opt-in. Without this
     * a re-run during a demo would silently throw away a state somebody had
     * spent ten minutes setting up by hand.
     */
    public function test_an_existing_file_is_left_alone_without_force(): void
    {
        $target = $this->workspace.'/demo.sqlite';
        file_put_contents($target, 'not-a-database');

        $this->artisan('mealbells:demo-db', ['--path' => $target])
            ->expectsOutputToContain('Pass --force to rebuild it from scratch.')
            ->assertExitCode(0);

        $this->assertSame('not-a-database', file_get_contents($target));
    }

    /**
     * Pointing .env at the demo file is how you demo it, so the guard has to
     * let go of a database it built itself. The first version of this command
     * refused, which made it unusable the moment anybody started using it.
     */
    public function test_a_demo_database_in_use_can_be_rebuilt(): void
    {
        $target = $this->workspace.'/demo.sqlite';

        $this->artisan('mealbells:demo-db', ['--path' => $target])->assertExitCode(0);

        config(['database.connections.sqlite.database' => $target]);

        $this->artisan('mealbells:demo-db', ['--path' => $target, '--force' => true])
            ->expectsOutputToContain('it is a demo database')
            ->assertExitCode(0);

        $demo = new PDO('sqlite:'.$target);
        $this->assertSame(20, (int) $demo->query('SELECT COUNT(*) FROM companies')->fetchColumn());
    }

    /**
     * .env naming a file nobody has built yet is the ordinary state on a fresh
     * checkout. There is nothing there to protect, and refusing would leave no
     * way to create it.
     */
    public function test_a_configured_path_that_does_not_exist_yet_is_built(): void
    {
        $target = $this->workspace.'/not-yet.sqlite';
        config(['database.connections.sqlite.database' => $target]);

        $this->artisan('mealbells:demo-db', ['--path' => $target])->assertExitCode(0);

        $this->assertFileExists($target);
    }

    /**
     * The control.
     *
     * Without it every refusal above would still pass if the command had been
     * reduced to a no-op, and the file it is supposed to produce is the only
     * evidence that the refusals are protecting real work.
     */
    public function test_it_builds_a_separate_file_holding_the_demo_data(): void
    {
        $target = $this->workspace.'/demo.sqlite';

        $this->artisan('mealbells:demo-db', ['--path' => $target])
            ->assertExitCode(0);

        $this->assertFileExists($target);

        $demo = new PDO('sqlite:'.$target);

        $this->assertSame(20, (int) $demo->query('SELECT COUNT(*) FROM companies')->fetchColumn());
        $this->assertGreaterThan(500, (int) $demo->query('SELECT COUNT(*) FROM employees')->fetchColumn());

        // The gap this command's demo data was missing: four screens read a
        // daily override and the seeder created none, so it was unreachable.
        $this->assertSame(1, (int) $demo->query('SELECT COUNT(*) FROM daily_overrides')->fetchColumn());

        // And the connection is handed back. Left pointed at the demo file,
        // everything running afterwards in this process would read and write
        // demo data while believing it was on the real database - which is how
        // the first version of this command behaved.
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
