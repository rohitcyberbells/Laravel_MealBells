<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The scheduled pull has to actually run.
 *
 * It did not: `['--all' => true]` renders as `--all='1'`, and Symfony refuses a
 * value on a boolean option, so every scheduled run died before the command
 * started - 17 identical errors in one afternoon's log. The earlier test
 * asserted the cron expression and the summary string, both of which were
 * perfectly correct while nothing worked.
 *
 * So these tests take the arguments off the registered schedule and run them
 * through Artisan, which is the only way to catch an argument the command
 * refuses to parse.
 */
class ScheduledPullRunsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, Event> */
    /**
     * The leave pull's entries, and only those.
     *
     * Matched on the whole command word. A plain str_contains('hrms:pull') also
     * catches hrms:pull-attendance, and argsFor() below then builds
     * `hrms:pull -attendance --all`, which fails with "the -a option does not
     * exist" - a failure about this filter, not about anything being wrong.
     */
    protected function pullEvents(): array
    {
        return array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event) => preg_match('/\bhrms:pull(?![\w-])/', $event->command ?? '') === 1,
        ));
    }

    /**
     * The argument string as the scheduler would hand it to the shell, reduced
     * to what artisan itself receives.
     */
    protected function argsFor(Event $event): string
    {
        $command = $event->command ?? '';
        $at = strpos($command, 'hrms:pull');

        return trim(substr($command, $at + strlen('hrms:pull')));
    }

    /**
     * The leave pull is paused (routes/console.php, 2026-11-17), so the tests
     * that exercise its scheduled entries have nothing to run against.
     *
     * Skipped rather than deleted, and skipped conditionally rather than
     * unconditionally: the moment the entries are uncommented these come back
     * on their own, which is when the `--all='1'` guard below matters again.
     */
    protected function skipWhilePaused(): void
    {
        if ($this->pullEvents() === []) {
            $this->markTestSkipped('The leave pull is paused in routes/console.php; uncomment it to run this.');
        }
    }

    /**
     * The pause is a decision, so it is asserted rather than left implied.
     *
     * When somebody restores the schedule this fails, and the failure is the
     * reminder to delete this test and update NEXT-SESSION.md §0.9.
     */
    public function test_the_leave_pull_is_paused(): void
    {
        $this->assertSame(
            [],
            $this->pullEvents(),
            'The leave pull is scheduled again. Delete this test and restore the ones below it.',
        );
    }

    public function test_both_pull_entries_are_scheduled(): void
    {
        $this->skipWhilePaused();

        $events = $this->pullEvents();

        $this->assertCount(2, $events);

        $interval = (int) config('hrms.cyberpulse.pull_every_minutes');
        $expressions = array_map(fn (Event $e) => $e->expression, $events);

        $this->assertContains("*/{$interval} * * * *", $expressions);
        $this->assertContains('* * * * *', $expressions);
    }

    /**
     * The test that matters. Running the scheduled arguments through Artisan is
     * what proves the command accepts them - a boolean option given a value
     * exits non-zero with "does not accept a value" before any work happens.
     */
    public function test_every_scheduled_pull_actually_runs(): void
    {
        $this->skipWhilePaused();

        Http::preventStrayRequests();

        $events = $this->pullEvents();
        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $args = $this->argsFor($event);

            $exit = Artisan::call('hrms:pull '.$args);
            $output = Artisan::output();

            $this->assertEquals(0, $exit, "`hrms:pull {$args}` exited {$exit}: {$output}");
            $this->assertStringNotContainsString('does not accept a value', $output);
            $this->assertStringNotContainsString('not enough arguments', strtolower($output));
            $this->assertStringNotContainsString('is not defined', $output);
        }
    }

    /**
     * No company has pull credentials in a fresh database, so both entries
     * should decide there is nothing to do - quietly, and without reaching the
     * network, which preventStrayRequests enforces.
     */
    public function test_a_scheduled_pull_with_no_configured_company_does_nothing(): void
    {
        Http::preventStrayRequests();

        foreach ($this->pullEvents() as $event) {
            Artisan::call('hrms:pull '.$this->argsFor($event));
            $this->assertStringContainsString('Nothing due.', Artisan::output());
        }

        $this->assertDatabaseCount('hrms_pull_runs', 0);
        $this->assertDatabaseCount('skips', 0);
    }

    /**
     * The flags have to be the bare form. Spelled as an associative array they
     * become --all='1', which is the bug.
     */
    public function test_the_flags_are_passed_without_values(): void
    {
        $this->skipWhilePaused();

        foreach ($this->pullEvents() as $event) {
            $this->assertStringNotContainsString("--all='", $event->command ?? '');
            $this->assertStringNotContainsString("--before-cutoff='", $event->command ?? '');
        }

        // Asserted on the registered commands above rather than on the source
        // text: the file's own comment names the broken form on purpose, so
        // grepping for its absence would fight the documentation.
        $commands = array_map(fn (Event $e) => $e->command, $this->pullEvents());

        $this->assertTrue(
            collect($commands)->contains(fn ($c) => str_ends_with(trim($c), '--all')),
            'no entry passes a bare --all: '.implode(' | ', $commands),
        );
        $this->assertTrue(
            collect($commands)->contains(fn ($c) => str_ends_with(trim($c), '--all --before-cutoff')),
            'no entry passes bare --all --before-cutoff: '.implode(' | ', $commands),
        );
    }

    /**
     * The same guard, on an entry that is still scheduled.
     *
     * The `--all='1'` bug is a property of how flags are written in
     * routes/console.php, not of the leave pull in particular - and while the
     * leave pull is paused the test above is skipped, which would leave the
     * lesson untested. The attendance entry passes the same two bare flags, so
     * it keeps the guard awake until the pull comes back.
     */
    public function test_the_attendance_entry_passes_bare_flags(): void
    {
        $attendance = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event) => str_contains($event->command ?? '', 'hrms:pull-attendance'),
        ));

        $this->assertCount(1, $attendance, 'the attendance pull is no longer scheduled');

        $command = trim($attendance[0]->command ?? '');

        $this->assertStringNotContainsString("--all='", $command);
        $this->assertStringNotContainsString("--before-cutoff='", $command);
        $this->assertStringEndsWith('--all --before-cutoff', $command);
    }

    /**
     * The prune entry does take a value, so it must not be "fixed" the same way.
     */
    public function test_the_prune_entry_still_passes_its_models(): void
    {
        $prune = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $e) => str_contains($e->command ?? '', 'model:prune'),
        ));

        $this->assertCount(1, $prune);
        $this->assertStringContainsString('HrmsWebhookEvent', $prune[0]->command);
        $this->assertStringContainsString('HrmsPullRun', $prune[0]->command);

        $this->assertEquals(0, Artisan::call('model:prune '.trim(
            substr($prune[0]->command, strpos($prune[0]->command, 'model:prune') + strlen('model:prune'))
        )));
    }

    /**
     * Every other scheduled command, so a flag mistake anywhere in the file is
     * caught rather than only in the two entries this test was written for.
     */
    public function test_no_scheduled_command_gives_a_value_to_a_boolean_flag(): void
    {
        Http::preventStrayRequests();

        foreach (app(Schedule::class)->events() as $event) {
            $command = $event->command ?? '';

            if (! str_contains($command, 'artisan')) {
                continue;
            }

            $this->assertStringNotContainsString(
                "--all='",
                $command,
                "a boolean flag was given a value: {$command}",
            );
        }
    }
}
