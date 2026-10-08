<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What must not happen when APP_ENV is production.
 *
 * `hrms:simulate` and `DemoSeeder` are covered in HrmsProductionGuardsTest and
 * DemoSeederTest. These are the ones that were not.
 */
class ProductionModeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * DatabaseSeeder is what plain `php artisan db:seed` runs - the one a
     * deploy script is most likely to contain by accident. It would create a
     * tiffin service, a company and three accounts whose password is
     * 'password'.
     */
    /**
     * Run the seeder itself, not `db:seed`.
     *
     * Going through the command proves nothing: Laravel's own confirmable
     * guard aborts `db:seed` in production before the seeder is reached, so the
     * assertion passes whether or not the seeder has a guard of its own. That
     * is how the first version of this test was green with the guard deleted.
     *
     * `db:seed --force` skips the framework's prompt, which is exactly what a
     * deploy script does - and then the seeder's own check is the only thing
     * left.
     */
    protected function runDefaultSeeder(): void
    {
        $seeder = new DatabaseSeeder;
        $seeder->setContainer(app());
        $seeder->setCommand(new class extends Command
        {
            public function info($string, $verbosity = null): void {}

            public function line($string, $style = null, $verbosity = null): void {}
        });

        $seeder->run();
    }

    public function test_the_default_seeder_refuses_outside_local_and_testing(): void
    {
        app()['env'] = 'production';

        $this->runDefaultSeeder();

        $this->assertSame(0, Company::count());
        $this->assertSame(0, User::count());
    }

    /**
     * The control. Without it the test above would pass even if the seeder were
     * empty, renamed or broken.
     */
    public function test_the_default_seeder_does_run_in_testing(): void
    {
        $this->runDefaultSeeder();

        $this->assertGreaterThan(0, Company::count());
        $this->assertGreaterThan(0, User::count());
    }

    /**
     * And the outer guard, which is what stops a plain `php artisan db:seed`
     * typed on a server by hand.
     */
    public function test_db_seed_without_force_is_refused_in_production(): void
    {
        $this->withoutMockingConsoleOutput();

        app()['env'] = 'production';

        // withoutMockingConsoleOutput() makes artisan() return the exit code
        // rather than a pending command, so the status is checked directly.
        ob_start();
        $exitCode = $this->artisan('db:seed');
        ob_end_clean();

        $this->assertNotSame(0, $exitCode, 'db:seed was allowed to proceed in production');
        $this->assertSame(0, Company::count());
    }

    /**
     * Guests have nowhere public to go, and a production deploy should not be
     * serving a framework welcome page.
     */
    public function test_a_guest_is_sent_to_the_sign_in_page(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    /**
     * The trap this guard exists for: phpunit.xml pins the suite to sqlite in
     * memory with <env> entries, and those cannot override a cached config -
     * the cached file wins. So after `php artisan config:cache`, the suite
     * points at whatever database that config names, and RefreshDatabase is
     * willing to migrate:fresh it.
     *
     * It happened during a production-mode rehearsal: the suite ran against the
     * rehearsal PostgreSQL database instead of :memory:.
     */
    public function test_the_suite_refuses_to_run_against_a_cached_config(): void
    {
        $this->assertFileDoesNotExist(
            base_path('bootstrap/cache/config.php'),
            'a cached config is present, which this very guard should have caught',
        );

        $guard = file_get_contents(base_path('tests/TestCase.php'));

        $this->assertStringContainsString('bootstrap/cache/config.php', $guard);
        $this->assertStringContainsString('config:clear', $guard);
    }
}
