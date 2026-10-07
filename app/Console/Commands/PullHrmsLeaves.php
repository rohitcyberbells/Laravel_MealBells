<?php

namespace App\Console\Commands;

use App\Actions\Hrms\PullHrmsLeaves as PullAction;
use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Pulls approved leave from an HRMS that cannot push it.
 *
 * All of the work is in the action, so the scheduler and this command exercise
 * exactly the same path. --dry-run reports what a real run would do and writes
 * nothing, which is the safe way to point this at a live HR system for the first
 * time.
 */
class PullHrmsLeaves extends Command
{
    protected $signature = 'hrms:pull
                            {company? : Company code; optional when exactly one company has pull credentials}
                            {--all : Pull every company that has credentials, for the scheduler}
                            {--before-cutoff : With --all, only companies entering their pre-cutoff window that have not been pulled in it}
                            {--dry-run : Report what would be applied, cancelled and ignored without writing}';

    protected $description = 'Pull approved leave and WFH from a company\'s HRMS';

    public function handle(PullAction $pull): int
    {
        if ($this->option('all')) {
            return $this->pullEveryCompany($pull);
        }

        $company = $this->resolveCompany();

        if (! $company) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->line("Pulling leave for {$company->name} ({$company->code})".($dryRun ? ' - dry run, nothing will be written' : ''));

        $summary = $pull->execute($company, $dryRun);

        if (! $summary['ok']) {
            $this->error($summary['error'] ?? 'The pull failed.');

            foreach ($summary['warnings'] as $warning) {
                $this->warn($warning);
            }

            return self::FAILURE;
        }

        $this->table(['', 'Count'], [
            ['Leaves fetched', $summary['fetched']],
            ['Approved and still ahead', $summary['approved_future']],
            [$dryRun ? 'Would apply' : 'Applied', $summary['applied']],
            [$dryRun ? 'Would cancel' : 'Cancelled', $summary['cancelled']],
            ['Ignored (partial day, WFH off, non-meal days)', $summary['ignored']],
            ['Unknown employee', $summary['unknown_employee']],
            ['Unreadable', $summary['unreadable']],
            ['Already seen on an earlier run', $summary['duplicate']],
        ]);

        foreach ($summary['warnings'] as $warning) {
            $this->warn($warning);
        }

        if ($summary['unmatched'] !== []) {
            $this->newLine();
            $this->warn(count($summary['unmatched']).' leave(s) matched no employee in MealBells:');
            $this->table(
                ['HR employee id', 'Email', 'Leave'],
                collect($summary['unmatched'])->map(fn (array $row) => [
                    $row['employee_ref'] ?: '—',
                    $row['employee_email'] ?: '—',
                    $row['leave'],
                ])->all(),
            );
            $this->line('Set external_id or email on the matching employee, then run again.');
        }

        if ($this->output->isVerbose()) {
            $this->newLine();
            $this->table(
                ['Leave', 'Action', 'Outcome', 'Dates'],
                collect($summary['details'])->map(fn (array $row) => [
                    $row['leave'],
                    $row['action'],
                    $row['outcome'],
                    implode(', ', $row['dates'] ?? []),
                ])->all(),
            );
        }

        $this->newLine();
        $this->line($summary['status'] === 'suspicious'
            ? '<comment>Run completed but marked suspicious - a safety guard stopped it acting.</comment>'
            : '<info>Run completed.</info>');

        return self::SUCCESS;
    }

    /**
     * The scheduler's path: every configured company, one failure not stopping
     * the rest. Exit code reflects whether any company failed, so a cron that
     * reports non-zero still tells the truth.
     */
    protected function pullEveryCompany(PullAction $pull): int
    {
        $connections = CompanyHrmsConnection::whereNotNull('pull_base_url')->get();
        $dryRun = (bool) $this->option('dry-run');
        $failed = 0;
        $ran = 0;

        foreach ($connections as $connection) {
            $company = Company::with('setting')->find($connection->company_id);

            if (! $company) {
                continue;
            }

            if ($this->option('before-cutoff') && ! $this->isDueBeforeCutoff($company, $connection)) {
                continue;
            }

            $ran++;
            $summary = $pull->execute($company, $dryRun);

            $line = "{$company->code}: applied {$summary['applied']}, cancelled {$summary['cancelled']}, ignored {$summary['ignored']}, unknown {$summary['unknown_employee']}";

            if (! $summary['ok']) {
                $failed++;
                $this->error("{$company->code}: {$summary['error']}");
            } elseif ($summary['status'] === 'suspicious') {
                $this->warn($line.' - marked suspicious');
            } else {
                $this->line($line);
            }
        }

        if ($ran === 0) {
            $this->line('Nothing due.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * True inside the window before this company's cutoff, and only until a pull
     * has actually happened in it.
     *
     * The regular cadence can leave a gap of up to its interval right before the
     * count locks, which is exactly when leave approved that morning matters
     * most. This runs every minute but acts at most once per company per day,
     * because last_pull_at has to predate the window for it to be due.
     */
    protected function isDueBeforeCutoff(Company $company, CompanyHrmsConnection $connection): bool
    {
        $setting = $company->setting;

        if (! $setting) {
            return false;
        }

        $timezone = $setting->timezone ?? config('mealbells.default_timezone', 'Asia/Kolkata');
        $lead = (int) config('hrms.cyberpulse.pull_before_cutoff_minutes', 20);

        [$hour, $minute] = array_pad(explode(':', (string) ($setting->cutoff_time ?? '11:00')), 2, '0');

        $cutoff = Carbon::today($timezone)->setTime((int) $hour, (int) $minute);
        $windowOpens = $cutoff->copy()->subMinutes($lead);
        $now = Carbon::now($timezone);

        if ($now->lt($windowOpens) || $now->gte($cutoff)) {
            return false;
        }

        return $connection->last_pull_at === null
            || $connection->last_pull_at->setTimezone($timezone)->lt($windowOpens);
    }

    protected function resolveCompany(): ?Company
    {
        $code = $this->argument('company');

        if ($code) {
            $company = Company::with('setting')->where('code', strtoupper(trim((string) $code)))->first();

            if (! $company) {
                $this->error("No company with code '{$code}'.");

                return null;
            }

            return $company;
        }

        // Only unambiguous when exactly one company is set up to be pulled;
        // guessing would point a live fetch at the wrong tenant.
        $configured = CompanyHrmsConnection::whereNotNull('pull_base_url')->pluck('company_id');

        if ($configured->count() !== 1) {
            $this->error($configured->isEmpty()
                ? 'No company has HRMS pull credentials configured.'
                : 'Several companies have pull credentials; pass the company code.');

            return null;
        }

        return Company::with('setting')->find($configured->first());
    }
}
