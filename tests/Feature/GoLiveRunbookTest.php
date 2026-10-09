<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The go-live runbook, checked against the application it describes.
 *
 * A runbook is followed once, by somebody who cannot tell a stale instruction
 * from a current one, under time pressure. A command that was renamed or a knob
 * that moved turns the page from a help into a trap - so the parts that can be
 * checked mechanically are.
 *
 * What this cannot check is whether the order is right or the advice is good.
 * That still needs reading.
 */
class GoLiveRunbookTest extends TestCase
{
    protected function runbook(): string
    {
        $path = base_path('docs/go-live.md');

        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    /** @return array<string, array<int, string>> */
    public static function commands(): array
    {
        return [
            'first super admin' => ['mealbells:create-super-admin'],
            'health token' => ['mealbells:health-token'],
            'health alerts' => ['mealbells:health-alerts'],
            'backup' => ['mealbells:backup'],
            'the cutoff' => ['mealbells:process-cutoff'],
        ];
    }

    /**
     * Every command the runbook tells someone to type still exists.
     */
    #[DataProvider('commands')]
    public function test_the_command_exists_and_is_named_in_the_runbook(string $command): void
    {
        $this->assertArrayHasKey(
            $command,
            Artisan::all(),
            "the runbook names {$command}, which is not a registered command",
        );

        $this->assertStringContainsString($command, $this->runbook());
    }

    /**
     * The options quoted for create-super-admin are real. --generate-password
     * is the one that matters: the alternative puts a password in shell
     * history.
     */
    public function test_the_super_admin_options_quoted_are_real(): void
    {
        $definition = Artisan::all()['mealbells:create-super-admin']->getDefinition();

        foreach (['email', 'name', 'generate-password'] as $option) {
            $this->assertTrue(
                $definition->hasOption($option),
                "the runbook quotes --{$option}, which the command does not accept",
            );
        }

        $this->assertStringContainsString('--generate-password', $this->runbook());
    }

    /** @return array<string, array<int, string>> */
    public static function settings(): array
    {
        return [
            'health ping token' => ['HEALTH_PING_TOKEN', 'health.ping_token'],
            'alert recipients' => ['HEALTH_ALERT_RECIPIENTS', 'health.alerts.recipients'],
            'backup directory' => ['BACKUP_DIRECTORY', 'backup.directory'],
        ];
    }

    /**
     * Every variable the runbook tells someone to set is still read by the
     * config key it is supposed to feed.
     */
    #[DataProvider('settings')]
    public function test_the_setting_is_still_wired_and_is_named_in_the_runbook(string $variable, string $configKey): void
    {
        $this->assertStringContainsString($variable, $this->runbook());

        $this->assertStringContainsString(
            $variable,
            file_get_contents(config_path(explode('.', $configKey)[0].'.php')),
            "the runbook tells someone to set {$variable}, which {$configKey} no longer reads",
        );
    }

    public function test_the_health_endpoint_it_points_a_monitor_at_exists(): void
    {
        $this->assertStringContainsString('/health/ping', $this->runbook());

        $paths = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => '/'.ltrim($route->uri(), '/'))
            ->all();

        $this->assertContains('/health/ping', $paths);
        // Named too, because the runbook is explicit that /up is not a
        // substitute and both are expected to exist.
        $this->assertStringContainsString('/up', $this->runbook());
    }

    /**
     * The runbook claims nothing but companies and tiffin services is
     * soft-deleted, and leans on that when it says to take a dump before a
     * migration. If a third model gains soft deletes the claim is stale.
     */
    public function test_the_soft_delete_claim_is_still_true(): void
    {
        // Through the class rather than the source text: grepping for
        // "use SoftDeletes;" misses a model that imports the trait
        // fully qualified, and a check that can be dodged by a formatting
        // choice is not a check. Proven by mutation - the grep version passed
        // with the trait added as \Illuminate\...\SoftDeletes.
        $softDeleted = collect(glob(app_path('Models/*.php')))
            ->map(fn (string $file) => 'App\\Models\\'.basename($file, '.php'))
            ->filter(fn (string $class) => class_exists($class) && in_array(
                SoftDeletes::class,
                class_uses_recursive($class),
                true,
            ))
            ->map(fn (string $class) => class_basename($class))
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['Company', 'TiffinService'], $softDeleted);
    }

    /**
     * The five settings the runbook says to check by eye, because each one
     * causes real harm if wrong. They are asserted against the production
     * example rather than the runbook's prose, so the two cannot disagree.
     */
    public function test_the_five_dangerous_settings_are_the_ones_the_example_sets(): void
    {
        $runbook = $this->runbook();

        foreach (['APP_ENV', 'APP_DEBUG', 'SESSION_SECURE_COOKIE', 'MAIL_MAILER', 'MAIL_FROM_ADDRESS'] as $variable) {
            $this->assertStringContainsString($variable, $runbook);
            $this->assertStringContainsString($variable, file_get_contents(base_path('.env.example')));
        }
    }

    /**
     * The two traps that cost the most to learn the hard way, both found during
     * a production-mode rehearsal. They are in the runbook because neither is
     * discoverable: one makes mail look broken, the other points the test suite
     * at the production database.
     */
    public function test_the_two_rehearsal_traps_are_written_down(): void
    {
        $runbook = $this->runbook();

        $this->assertStringContainsString('LOG_LEVEL=warning', $runbook);
        $this->assertStringContainsString('config:clear', $runbook);
    }

    /**
     * It has to say that none of this has run on a real server, because that is
     * the single most useful thing a first-time deployer can know.
     */
    public function test_it_is_honest_about_never_having_been_deployed(): void
    {
        $this->assertStringContainsString('has run on a real server', $this->runbook());
    }
}
