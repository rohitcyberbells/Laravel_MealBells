<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The handover, checked against the project it describes.
 *
 * It is read once, cold, by somebody with no context - possibly an agent - who
 * cannot tell a stale instruction from a current one. A path that moved or a
 * command that was renamed turns the page from a help into a trap, so the parts
 * that can be checked mechanically are.
 *
 * What this cannot check is whether the advice is any good. That still needs
 * reading.
 */
class HandoverTest extends TestCase
{
    protected function handover(): string
    {
        $path = base_path('HANDOVER.md');

        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    /** @return array<string, array<int, string>> */
    public static function referencedFiles(): array
    {
        return [
            'go-live runbook' => ['docs/go-live.md'],
            'deploy reference' => ['docs/deploy.md'],
            'backup and restore' => ['docs/backup-restore.md'],
            'demo day' => ['docs/demo-day.md'],
            'hrms contract' => ['docs/hrms-event-contract.md'],
            'attendance design' => ['docs/attendance-design.md'],
            'attendance endpoint spec' => ['docs/cyberpulse-attendance-endpoint-spec.md'],
            'cyberpulse orientation' => ['docs/cyberpulse-orientation.md'],
            'screen flows' => ['SCREEN_FLOWS.md'],
            'conventions' => ['CLAUDE.md'],
            'ci workflow' => ['.github/workflows/tests.yml'],
            'the guard order' => ['app/Services/MealGuard.php'],
            'first source wins' => ['app/Actions/Meal/RecordSkip.php'],
            'the case guard' => ['tests/Feature/InertiaPageComponentTest.php'],
        ];
    }

    /**
     * Every path it sends somebody to still exists, and is still named.
     */
    #[DataProvider('referencedFiles')]
    public function test_the_referenced_path_exists_and_is_named(string $path): void
    {
        $this->assertFileExists(base_path($path));
        $this->assertStringContainsString($path, $this->handover());
    }

    /** @return array<string, array<int, string>> */
    public static function referencedCommands(): array
    {
        return [
            'attendance pull' => ['hrms:pull-attendance'],
            'backup' => ['mealbells:backup'],
        ];
    }

    #[DataProvider('referencedCommands')]
    public function test_the_referenced_command_still_exists(string $command): void
    {
        $this->assertArrayHasKey($command, Artisan::all());
        $this->assertStringContainsString($command, $this->handover());
    }

    /**
     * The stale-docs warning has to stay while those files are still stale. If
     * somebody rewrites them, this is the reminder to drop the warning.
     */
    public function test_it_warns_that_the_old_docs_are_stale(): void
    {
        $handover = $this->handover();

        $this->assertStringContainsString('stale', $handover);
        $this->assertStringContainsString('KT.md', $handover);
        $this->assertStringContainsString('PROGRESS_LOG.md', $handover);

        // Still true: these describe MySQL and a Flutter app, neither of which
        // happened. The day that changes, the warning should go.
        $this->assertStringContainsString('MySQL', file_get_contents(base_path('KT.md')));
    }

    /**
     * The traps section is the most valuable part of the file - each entry cost
     * real time here. A later edit must not quietly drop one.
     */
    public function test_every_trap_that_has_bitten_is_still_recorded(): void
    {
        $handover = $this->handover();

        foreach ([
            'bootstrap/cache/config.php',   // the suite against a production database
            'does not pass `DB_DATABASE`',  // artisan serve
            'case-insensitive',             // Inertia page path on Linux
            'aborts the whole transaction', // Postgres failed INSERT
            'LOG_LEVEL=warning',            // the log mailer silenced
            'mass-delete',                  // model events not firing
            'assertSentTo',                 // does not count
            'forgetGuards',                 // actingAs pins an instance
            'whereStrict',                  // null == false
        ] as $trap) {
            $this->assertStringContainsString($trap, $handover, "the handover no longer records: {$trap}");
        }
    }

    /**
     * The constraints the owner has repeated. Dropping one of these from the
     * handover is how an agent ends up pushing unasked or editing the live HRMS
     * repository.
     */
    public function test_the_standing_constraints_are_written_down(): void
    {
        $handover = $this->handover();

        foreach ([
            'Do not push',
            'Web_CyberPulse',
            'Do not remove tests',
            'dev database',
        ] as $rule) {
            $this->assertStringContainsString($rule, $handover, "the handover no longer states: {$rule}");
        }
    }

    /**
     * The single most useful thing a first-time deployer can be told, and the
     * easiest to quietly lose once something is finally deployed.
     */
    public function test_it_is_honest_about_never_having_been_deployed(): void
    {
        $this->assertStringContainsString('never run on a real server', $this->handover());
    }

    /**
     * Phase 2 must not start before Phase 1 has produced a number - that is the
     * whole design. The handover is where somebody picking this up cold would
     * learn it.
     */
    public function test_it_says_not_to_start_phase_two_yet(): void
    {
        $handover = $this->handover();

        $this->assertStringContainsString('Phase 1 measures, Phase 2 decides', $handover);
        $this->assertStringContainsString('Do not start Phase 2', $handover);
    }
}
