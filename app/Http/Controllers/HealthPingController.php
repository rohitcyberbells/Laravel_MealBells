<?php

namespace App\Http\Controllers;

use App\Models\CompanyHrmsConnection;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What an uptime monitor can reach.
 *
 * `/up` only proves the application boots. It answers 200 with the database
 * unreachable, the queue worker stopped and the scheduler dead - which is to
 * say it stays green through every failure MealBells actually has. The real
 * signals were behind a login on /super-admin/health, where no monitor can read
 * them and a person has to remember to look.
 *
 * This endpoint is the same signals, machine-readable, behind a shared secret.
 * It deliberately does no work beyond counting: a monitor hits it every minute,
 * from an unauthenticated position, so every check here is a cheap indexed
 * query or a cache read.
 */
class HealthPingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->assertTokenMatches($request);

        $checks = [
            'database' => $this->databaseCheck(),
            'scheduler' => $this->schedulerCheck(),
            'queue_failures' => $this->queueFailureCheck(),
            'queue_worker' => $this->queueWorkerCheck(),
            'hrms_pull' => $this->hrmsPullCheck(),
        ];

        $failing = array_keys(array_filter($checks, fn (array $check) => $check['ok'] === false));

        return response()->json([
            'status' => $failing === [] ? 'ok' : 'failing',
            'failing' => $failing,
            'checks' => $checks,
            'checked_at' => now()->toIso8601String(),
        ], $failing === [] ? 200 : 503);
    }

    /**
     * No token configured means no endpoint.
     *
     * 404 rather than 401 both times, so the response cannot be used to learn
     * that the path exists or that a guessed token was close. hash_equals
     * because a plain === leaks the matching prefix through timing.
     */
    protected function assertTokenMatches(Request $request): void
    {
        $expected = (string) config('health.ping_token', '');

        if ($expected === '') {
            abort(404);
        }

        $given = (string) ($request->header('X-Health-Token') ?? $request->query('token', ''));

        if (! hash_equals($expected, $given)) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function databaseCheck(): array
    {
        try {
            DB::connection()->select('select 1');

            return ['ok' => true];
        } catch (Throwable $e) {
            // The class, not the message: a connection error carries the host,
            // the database name and sometimes the user.
            return ['ok' => false, 'detail' => 'query failed: '.class_basename($e)];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function schedulerCheck(): array
    {
        $staleAfter = (int) config('health.scheduler_stale_after_minutes', 3);
        $lastRun = Cache::get('scheduler_last_run');

        if (! $lastRun) {
            return [
                'ok' => false,
                'detail' => 'no heartbeat recorded; the cron entry may be missing',
                'stale_after_minutes' => $staleAfter,
            ];
        }

        $minutesAgo = (int) Carbon::createFromTimestamp($lastRun)->diffInMinutes(now());

        return [
            'ok' => $minutesAgo <= $staleAfter,
            'minutes_ago' => $minutesAgo,
            'stale_after_minutes' => $staleAfter,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function queueFailureCheck(): array
    {
        $threshold = (int) config('health.queue.failed_jobs_threshold', 25);
        $count = DB::table('failed_jobs')->count();

        return [
            'ok' => $count <= $threshold,
            'failed_jobs' => $count,
            'threshold' => $threshold,
        ];
    }

    /**
     * Whether anything is draining the queue.
     *
     * There is no worker heartbeat to read, so this infers one from the work
     * itself: a job that has sat unreserved past the threshold means nothing
     * picked it up. An empty queue is reported as ok with a note, because it is
     * genuinely no evidence either way - a monitor must not page someone
     * because nothing happened to be queued at 3am.
     *
     * @return array<string, mixed>
     */
    protected function queueWorkerCheck(): array
    {
        $staleAfter = (int) config('health.queue.pending_job_stale_after_minutes', 10);

        try {
            $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
        } catch (Throwable $e) {
            // A non-database queue driver has no jobs table to look at.
            return ['ok' => true, 'detail' => 'not measurable on this queue driver'];
        }

        if ($oldest === null) {
            return [
                'ok' => true,
                'detail' => 'queue empty, which is not evidence of a worker either way',
            ];
        }

        $waitingMinutes = (int) Carbon::createFromTimestamp($oldest)->diffInMinutes(now());

        return [
            'ok' => $waitingMinutes <= $staleAfter,
            'oldest_pending_job_minutes' => $waitingMinutes,
            'stale_after_minutes' => $staleAfter,
            'detail' => $waitingMinutes > $staleAfter
                ? 'a job has waited this long unreserved, so no worker is running'
                : null,
        ];
    }

    /**
     * Only companies that poll an HRMS. A site with none is unaffected.
     *
     * @return array<string, mixed>
     */
    protected function hrmsPullCheck(): array
    {
        $staleAfter = (int) config('hrms.cyberpulse.pull_every_minutes', 15) * 3;

        $connections = CompanyHrmsConnection::whereNotNull('pull_base_url')
            ->get(['company_id', 'last_pull_at', 'last_pull_status']);

        if ($connections->isEmpty()) {
            return ['ok' => true, 'detail' => 'no pull connections configured'];
        }

        $stale = $connections->filter(
            fn (CompanyHrmsConnection $connection) => $connection->last_pull_at === null
                || $connection->last_pull_at->diffInMinutes(now()) > $staleAfter
        );

        $failed = $connections->filter(
            fn (CompanyHrmsConnection $connection) => $connection->last_pull_status === 'error'
        );

        $problems = $stale->count() + $failed->count();

        return [
            // Company ids, never names, base urls or credentials: this response
            // is readable by anyone holding the monitor's token.
            'ok' => $problems === 0 || ! config('health.pull_staleness_fails', true),
            'connections' => $connections->count(),
            'stale' => $stale->count(),
            'failed' => $failed->count(),
            'stale_after_minutes' => $staleAfter,
        ];
    }
}
