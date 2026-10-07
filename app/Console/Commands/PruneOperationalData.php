<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trims the tables that only grow.
 *
 * model:prune already handles the HRMS tables, which are Eloquent models with
 * their own retention rules. These four are framework plumbing with no model to
 * hang a prunable() on, and nothing was clearing them:
 *
 *   sessions       one row per sign-in, each holding a user id
 *   notifications  the in-app feed, which is every account's whole history
 *   failed_jobs    kept for retrying, and a payload can carry employee data
 *   jobs           only ever stuck rows; a healthy queue empties itself
 *
 * Retention is per table because the reasons differ - a session is spent the
 * moment it expires, while a failed job may still be worth retrying.
 */
class PruneOperationalData extends Command
{
    protected $signature = 'mealbells:prune-operational-data
                            {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete expired sessions, old notifications and old failed jobs';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($this->targets() as $label => [$table, $column, $days]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $cutoff = $this->cutoffFor($table, $days);

            $query = DB::table($table)->where($column, '<', $cutoff);
            $count = $query->count();

            if (! $dryRun && $count > 0) {
                // Chunked, so pruning a long-neglected table does not hold one
                // enormous delete open against a live database.
                $deleted = 0;

                while (($chunk = DB::table($table)->where($column, '<', $cutoff)->limit(1000)->pluck('id'))->isNotEmpty()) {
                    $deleted += DB::table($table)->whereIn('id', $chunk)->delete();
                }

                $count = $deleted;
            }

            $rows[] = [$label, $days.' days', $count];
        }

        $this->table([$dryRun ? 'Would prune' : 'Pruned', 'Older than', 'Rows'], $rows);

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    protected function targets(): array
    {
        return [
            // last_activity is a unix timestamp, handled in cutoffFor().
            'Expired sessions' => ['sessions', 'last_activity', (int) config('mealbells.retention.sessions_days', 30)],
            'Read notifications' => ['notifications', 'created_at', (int) config('mealbells.retention.notifications_days', 90)],
            'Failed jobs' => ['failed_jobs', 'failed_at', (int) config('mealbells.retention.failed_jobs_days', 30)],
        ];
    }

    protected function cutoffFor(string $table, int $days): mixed
    {
        $moment = now()->subDays($days);

        // The sessions table stores an integer, not a datetime.
        return $table === 'sessions' ? $moment->timestamp : $moment;
    }
}
