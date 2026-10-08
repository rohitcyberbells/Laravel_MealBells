<?php

namespace Tests\Feature;

use App\Console\Commands\BackupDatabase;
use App\Models\User;
use App\Notifications\HealthIssueNotification;
use App\Notifications\HealthResolvedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Alerting, which did not exist.
 *
 * The health page had to be opened by a person, and the failures that cost the
 * most - the scheduler dead, the worker stopped, a pull that silently stopped -
 * all look exactly like a quiet day with no leave. These cover when a mail goes
 * out, who gets it, and the dedupe, because alerting that floods is worse than
 * none: it gets filtered, and then nothing is watching.
 */
class HealthAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // Everything healthy by default, so each test fails only the thing it
        // is about.
        Cache::put('scheduler_last_run', now()->timestamp);
        Cache::put(BackupDatabase::STATUS_KEY, ['ok' => true, 'at' => now()->toDateTimeString()]);

        $this->superAdmin = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test', 'password' => bcrypt('password'),
            'role' => 'super_admin', 'is_active' => true,
        ]);
    }

    protected function breakTheScheduler(): void
    {
        Cache::forget('scheduler_last_run');
    }

    public function test_nothing_is_sent_when_every_check_passes(): void
    {
        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_failing_check_mails_the_super_admin(): void
    {
        $this->breakTheScheduler();

        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        Notification::assertSentTo(
            $this->superAdmin,
            HealthIssueNotification::class,
            fn (HealthIssueNotification $notification) => $notification->issue === 'scheduler',
        );
    }

    /**
     * The dedupe. The command runs every five minutes; without it a dead worker
     * sends twelve mails an hour and is filtered within a day.
     */
    public function test_the_same_issue_is_not_mailed_again_inside_the_window(): void
    {
        $this->breakTheScheduler();

        $this->artisan('mealbells:health-alerts')->assertSuccessful();
        $this->artisan('mealbells:health-alerts')->assertSuccessful();
        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        Notification::assertSentToTimes($this->superAdmin, HealthIssueNotification::class, 1);
    }

    public function test_an_issue_still_failing_after_the_window_is_mailed_again(): void
    {
        $this->breakTheScheduler();

        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        $this->travel(61)->minutes();
        // The heartbeat is still gone, but travelling moved 'now'.
        $this->breakTheScheduler();

        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        Notification::assertSentToTimes($this->superAdmin, HealthIssueNotification::class, 2);
    }

    public function test_the_window_is_configurable(): void
    {
        config()->set('health.alerts.repeat_after_minutes', 5);

        $this->breakTheScheduler();
        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        $this->travel(6)->minutes();
        $this->breakTheScheduler();
        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        Notification::assertSentToTimes($this->superAdmin, HealthIssueNotification::class, 2);
    }

    /**
     * Without a closing message the reader cannot tell a fixed problem from one
     * nobody has looked at.
     */
    public function test_an_issue_that_clears_sends_one_resolved_mail(): void
    {
        $this->breakTheScheduler();
        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        Cache::put('scheduler_last_run', now()->timestamp);

        $this->artisan('mealbells:health-alerts')->assertSuccessful();
        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        Notification::assertSentToTimes($this->superAdmin, HealthResolvedNotification::class, 1);
    }

    public function test_a_check_that_was_never_failing_sends_no_resolved_mail(): void
    {
        $this->artisan('mealbells:health-alerts')->assertSuccessful();
        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        Notification::assertNotSentTo($this->superAdmin, HealthResolvedNotification::class);
    }

    public function test_the_resolved_mail_reports_how_long_it_was_failing(): void
    {
        $this->breakTheScheduler();
        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        $this->travel(90)->minutes();
        Cache::put('scheduler_last_run', now()->timestamp);

        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        Notification::assertSentTo(
            $this->superAdmin,
            HealthResolvedNotification::class,
            fn (HealthResolvedNotification $notification) => $notification->minutesFailing === 90,
        );
    }

    public function test_each_issue_is_deduped_on_its_own(): void
    {
        $this->breakTheScheduler();
        Cache::forget(BackupDatabase::STATUS_KEY);

        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        $issues = [];

        Notification::assertSentTo(
            $this->superAdmin,
            HealthIssueNotification::class,
            function (HealthIssueNotification $notification) use (&$issues) {
                $issues[] = $notification->issue;

                return true;
            },
        );

        sort($issues);
        $this->assertSame(['backup', 'scheduler'], $issues);
    }

    public function test_a_failed_backup_alerts(): void
    {
        Cache::put(BackupDatabase::STATUS_KEY, [
            'ok' => false, 'at' => now()->toDateTimeString(), 'error' => 'disk full',
        ]);

        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        Notification::assertSentTo(
            $this->superAdmin,
            HealthIssueNotification::class,
            fn (HealthIssueNotification $n) => $n->issue === 'backup'
                && str_contains($n->detail(), 'disk full'),
        );
    }

    public function test_a_stopped_worker_alerts(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subHour()->timestamp,
            'created_at' => now()->subHour()->timestamp,
        ]);

        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        Notification::assertSentTo(
            $this->superAdmin,
            HealthIssueNotification::class,
            fn (HealthIssueNotification $n) => $n->issue === 'queue_worker',
        );
    }

    public function test_configured_recipients_replace_the_super_admins(): void
    {
        config()->set('health.alerts.recipients', 'oncall@example.test, ops@example.test');

        $this->breakTheScheduler();
        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        Notification::assertNotSentTo($this->superAdmin, HealthIssueNotification::class);
        Notification::assertSentTimes(HealthIssueNotification::class, 2);

        Notification::assertSentTo(
            new AnonymousNotifiable,
            HealthIssueNotification::class,
            fn ($notification, $channels, AnonymousNotifiable $notifiable) => in_array(
                $notifiable->routeNotificationFor('mail'),
                ['oncall@example.test', 'ops@example.test'],
                true,
            ),
        );
    }

    /**
     * A deactivated account cannot sign in to act on the alert, and in several
     * cases the address belongs to someone who has left.
     */
    public function test_a_deactivated_super_admin_is_not_mailed(): void
    {
        $gone = User::create([
            'name' => 'Former', 'email' => 'former@mealbells.test', 'password' => bcrypt('password'),
            'role' => 'super_admin', 'is_active' => false,
        ]);

        $this->breakTheScheduler();
        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        Notification::assertSentTo($this->superAdmin, HealthIssueNotification::class);
        Notification::assertNotSentTo($gone, HealthIssueNotification::class);
    }

    public function test_alerting_can_be_turned_off(): void
    {
        config()->set('health.alerts.enabled', false);

        $this->breakTheScheduler();
        $this->artisan('mealbells:health-alerts')->assertSuccessful();

        Notification::assertNothingSent();
    }

    /**
     * Silent alerting is worse than none, because it is believed. So this is a
     * loud failure rather than a quiet pass.
     */
    public function test_having_nobody_to_alert_fails_the_command(): void
    {
        User::query()->delete();

        $this->breakTheScheduler();

        $this->artisan('mealbells:health-alerts')
            ->expectsOutputToContain('No alert recipients')
            ->assertFailed();
    }

    public function test_a_dry_run_sends_nothing_and_remembers_nothing(): void
    {
        $this->breakTheScheduler();

        $this->artisan('mealbells:health-alerts', ['--dry-run' => true])->assertSuccessful();

        Notification::assertNothingSent();

        // Having reported nothing, it must not have marked the issue as
        // reported - otherwise a dry run suppresses the real alert.
        $this->artisan('mealbells:health-alerts')->assertSuccessful();
        Notification::assertSentToTimes($this->superAdmin, HealthIssueNotification::class, 1);
    }

    public function test_the_notifications_are_queued(): void
    {
        $this->assertInstanceOf(
            ShouldQueue::class,
            new HealthIssueNotification('scheduler', []),
        );
        $this->assertInstanceOf(
            ShouldQueue::class,
            new HealthResolvedNotification('scheduler', 5),
        );
    }

    /**
     * An alert that only says "scheduler: false" sends someone to a laptop to
     * find out whether it matters.
     */
    public function test_the_mail_says_what_the_failure_costs_and_what_to_check(): void
    {
        $mail = (new HealthIssueNotification('queue_worker', [
            'ok' => false, 'detail' => 'a job has waited 41 minutes unreserved',
        ]))->toMail($this->superAdmin);

        $body = implode(' ', array_map('strval', [...$mail->introLines, ...$mail->outroLines]));

        $this->assertStringContainsString('41 minutes', $body);
        $this->assertStringContainsString('supervisorctl', $body);
        $this->assertStringContainsString('not even the in-app ones', $body);
    }

    public function test_the_resolved_mail_says_nothing_was_made_up_for(): void
    {
        $mail = (new HealthResolvedNotification('scheduler', 120))->toMail($this->superAdmin);

        $body = implode(' ', array_map('strval', [...$mail->introLines, ...$mail->outroLines]));

        $this->assertStringContainsString('has not been made up for automatically', $body);
    }
}
