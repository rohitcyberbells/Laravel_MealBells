<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The framework's own tables only grow.
 *
 * model:prune covers the HRMS tables, which are models with their own retention
 * rules. Sessions, the notification feed and failed jobs have no model to hang
 * a prunable() on, and nothing was clearing them - they hold user ids and job
 * payloads indefinitely.
 */
class OperationalPruneTest extends TestCase
{
    use RefreshDatabase;

    protected User $root;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);

        $this->root = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test',
            'password' => bcrypt('password-1'), 'role' => 'super_admin',
        ]);
    }

    protected function seedSession(int $daysAgo): string
    {
        $id = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $this->root->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => base64_encode('x'),
            'last_activity' => now()->subDays($daysAgo)->timestamp,
        ]);

        return $id;
    }

    protected function notification(int $daysAgo): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\Whatever',
            'notifiable_type' => User::class,
            'notifiable_id' => $this->root->id,
            'data' => json_encode(['title' => 'x']),
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ]);

        return $id;
    }

    protected function failedJob(int $daysAgo, string $queue = 'default'): int
    {
        return DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => json_encode(['employee_email' => 'alice@alpha.test']),
            'exception' => 'boom',
            'failed_at' => now()->subDays($daysAgo),
        ]);
    }

    // ------------------------------------------------------------- the pruning

    public function test_an_expired_session_is_removed_and_a_live_one_kept(): void
    {
        $old = $this->seedSession(daysAgo: 40);
        $recent = $this->seedSession(daysAgo: 2);

        $this->artisan('mealbells:prune-operational-data')->assertSuccessful();

        $this->assertDatabaseMissing('sessions', ['id' => $old]);
        $this->assertDatabaseHas('sessions', ['id' => $recent]);
    }

    public function test_an_old_notification_is_removed_and_a_recent_one_kept(): void
    {
        $old = $this->notification(daysAgo: 120);
        $recent = $this->notification(daysAgo: 10);

        $this->artisan('mealbells:prune-operational-data')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['id' => $old]);
        $this->assertDatabaseHas('notifications', ['id' => $recent]);
    }

    /**
     * A failed job's payload can carry employee data, so it does not live
     * forever - but it is kept longer than a session, because one may still be
     * worth retrying.
     */
    public function test_an_old_failed_job_is_removed_and_a_recent_one_kept(): void
    {
        $old = $this->failedJob(daysAgo: 45);
        $recent = $this->failedJob(daysAgo: 3);

        $this->artisan('mealbells:prune-operational-data')->assertSuccessful();

        $this->assertDatabaseMissing('failed_jobs', ['id' => $old]);
        $this->assertDatabaseHas('failed_jobs', ['id' => $recent]);
    }

    public function test_a_dry_run_reports_without_deleting(): void
    {
        $this->seedSession(daysAgo: 40);
        $this->notification(daysAgo: 120);
        $this->failedJob(daysAgo: 45);

        $this->artisan('mealbells:prune-operational-data', ['--dry-run' => true])
            ->expectsOutputToContain('Would prune')
            ->assertSuccessful();

        $this->assertEquals(1, DB::table('sessions')->count());
        $this->assertEquals(1, DB::table('notifications')->count());
        $this->assertEquals(1, DB::table('failed_jobs')->count());
    }

    public function test_the_retention_windows_are_configurable(): void
    {
        config()->set('mealbells.retention.sessions_days', 1);

        $this->seedSession(daysAgo: 5);

        $this->artisan('mealbells:prune-operational-data')->assertSuccessful();

        $this->assertEquals(0, DB::table('sessions')->count());
    }

    /**
     * sessions.last_activity is a unix integer, not a datetime - comparing a
     * Carbon instance against it would delete the wrong rows, or none.
     */
    public function test_the_session_cutoff_is_compared_as_a_timestamp(): void
    {
        $this->seedSession(daysAgo: 29);
        $this->seedSession(daysAgo: 31);

        $this->artisan('mealbells:prune-operational-data')->assertSuccessful();

        $this->assertEquals(1, DB::table('sessions')->count(), 'the wrong rows were matched');
    }

    public function test_running_it_on_empty_tables_is_harmless(): void
    {
        $this->artisan('mealbells:prune-operational-data')->assertSuccessful();

        $this->assertEquals(0, DB::table('sessions')->count());
    }

    public function test_it_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => str_contains($e->command ?? '', 'mealbells:prune-operational-data'));

        $this->assertNotNull($event, 'the prune is not scheduled');
        $this->assertEquals('15 3 * * *', $event->expression);
    }

    // --------------------------------------------------------- the health page

    /**
     * A count alone is not actionable: the oldest entry says whether this is a
     * burst from minutes ago or something that has sat for a week.
     */
    public function test_the_health_page_reports_what_is_needed_to_act(): void
    {
        $this->failedJob(daysAgo: 7, queue: 'default');
        $this->failedJob(daysAgo: 1, queue: 'notifications');
        $this->failedJob(daysAgo: 1, queue: 'notifications');

        $props = $this->actingAs($this->root)->get('/super-admin/health')
            ->getOriginalContent()->getData()['page']['props'];

        $failed = $props['failed_jobs'];

        $this->assertEquals(3, $failed['count']);
        $this->assertNotNull($failed['oldest_at']);
        $this->assertStringContainsString('queue:retry', $failed['retry_command']);

        $byQueue = collect($failed['by_queue'])->keyBy('queue');
        $this->assertEquals(2, $byQueue['notifications']->total);
        $this->assertEquals(1, $byQueue['default']->total);
    }

    public function test_the_page_renders_the_retry_command(): void
    {
        $page = file_get_contents(resource_path('js/Pages/SuperAdmin/Health.vue'));

        $this->assertStringContainsString('failed_jobs.retry_command', $page);
        $this->assertStringContainsString('failed_jobs.by_queue', $page);
        // Printed, not a button: retrying blindly can re-apply work a human
        // should look at first.
        $this->assertStringContainsString('queue:failed', $page);
    }

    public function test_a_healthy_queue_shows_nothing_extra(): void
    {
        $props = $this->actingAs($this->root)->get('/super-admin/health')
            ->getOriginalContent()->getData()['page']['props'];

        $this->assertEquals(0, $props['failed_jobs']['count']);
        $this->assertNull($props['failed_jobs']['oldest_at']);
    }
}
