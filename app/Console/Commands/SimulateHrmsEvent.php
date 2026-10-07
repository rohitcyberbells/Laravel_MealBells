<?php

namespace App\Console\Commands;

use App\Actions\Hrms\SendTestHrmsEvent;
use App\Models\Company;
use App\Models\HrmsWebhookEvent;
use Illuminate\Console\Command;

/**
 * Sends a correctly signed webhook to this application, as the vendor would.
 *
 * Useful for two things: demonstrating the integration without a live HRMS, and
 * onboarding a real one - `--print` emits the exact request to hand over, built
 * from that company's own payload_map rather than a hardcoded shape.
 *
 * The building and signing live in SendTestHrmsEvent, shared with the company
 * admin connect screen so both exercise identical rules.
 */
class SimulateHrmsEvent extends Command
{
    protected $signature = 'hrms:simulate
                            {--company= : Company code; optional when exactly one company is configured}
                            {--event=leave_approved : The vendor event string, e.g. leave_approved, wfh_approved, leave_cancelled}
                            {--employee= : HRMS employee reference (external_id, or employee_code)}
                            {--from= : Y-m-d; defaults to the next day the count is still open}
                            {--to= : Y-m-d; defaults to --from}
                            {--leave-type= : Vendor leave-type string, when the event name alone does not imply it}
                            {--leave-id= : Defaults to a generated reference}
                            {--event-id= : Defaults to a generated reference}
                            {--url= : Base URL to post to; defaults to config(app.url)}
                            {--print : Print the signed request instead of sending it}';

    protected $description = 'Send a signed HRMS webhook to this application for demos and vendor onboarding';

    public function handle(SendTestHrmsEvent $sender): int
    {
        // This posts a real signed event through the real pipeline, so it
        // creates real skips - someone's meal is cancelled by it. That is what
        // makes it useful for onboarding a vendor and unacceptable in
        // production, where there is no way to tell it from a genuine event.
        if (app()->environment('production')) {
            $this->error('hrms:simulate creates real skips and is refused in production.');
            $this->line('Use `hrms:pull {company} --dry-run` to check a connection without writing.');

            return self::FAILURE;
        }

        $company = $this->resolveCompany();

        if (! $company) {
            return self::FAILURE;
        }

        $result = $sender->execute($company, [
            'event' => (string) $this->option('event'),
            'employee' => $this->option('employee'),
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'leave_type' => $this->option('leave-type'),
            'leave_id' => $this->option('leave-id'),
            'event_id' => $this->option('event-id'),
            'url' => $this->option('url'),
            'send' => ! $this->option('print'),
        ]);

        if ($this->option('print')) {
            if (! $result['ok']) {
                $this->error($result['error']);

                return self::FAILURE;
            }

            $this->printRequest($result);

            return self::SUCCESS;
        }

        if (! $result['employee_matched']) {
            $this->warn("No employee in this company matches '{$this->option('employee')}'; the event will be recorded as blocked.");
        }

        if ($result['status'] === null) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->line("HTTP {$result['status']} {$result['body']}");

        if (! $result['ok']) {
            return self::FAILURE;
        }

        $this->reportOutcome($company, (string) $result['event_id']);

        return self::SUCCESS;
    }

    protected function resolveCompany(): ?Company
    {
        $configured = collect(config('hrms.companies', []))->keys();

        if ($code = $this->option('company')) {
            $company = Company::where('code', strtoupper(trim($code)))->first();

            if (! $company) {
                $this->error("No company with code '{$code}'.");

                return null;
            }

            return $company;
        }

        if ($configured->count() === 1) {
            return Company::find($configured->first());
        }

        $this->error($configured->isEmpty()
            ? 'No companies are configured in config/hrms.php.'
            : 'Several companies are configured; pass --company=CODE.');

        return null;
    }

    protected function reportOutcome(Company $company, string $eventId): void
    {
        $event = HrmsWebhookEvent::where('company_id', $company->id)
            ->where('external_event_id', $eventId)
            ->first();

        if (! $event) {
            return;
        }

        $this->line("Event {$event->external_event_id} status: <info>{$event->status}</info>");

        if ($event->status === HrmsWebhookEvent::STATUS_RECEIVED) {
            $this->warn('Still queued. Run `php artisan queue:work` to let the job apply it.');

            return;
        }

        foreach (['applied_days', 'already_days', 'released_days'] as $key) {
            if ($days = $event->result[$key] ?? []) {
                $this->line('  '.str_replace('_', ' ', $key).': '.implode(', ', $days));
            }
        }

        foreach (['blocked_days', 'release_blocked'] as $key) {
            foreach ($event->result[$key] ?? [] as $entry) {
                $this->line("  <comment>{$key}: {$entry['date']} ({$entry['reason']})</comment>");
            }
        }

        foreach ($event->result['notes'] ?? [] as $note) {
            $this->line("  <comment>{$note}</comment>");
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function printRequest(array $result): void
    {
        $body = json_encode($result['payload']);

        $this->line("POST {$result['url']}");

        foreach ($result['headers'] as $name => $value) {
            $this->line("{$name}: {$value}");
        }

        $this->line('Content-Type: application/json');
        $this->newLine();
        $this->line($body);
        $this->newLine();

        $headerFlags = collect($result['headers'])
            ->map(fn ($value, $name) => "-H '{$name}: {$value}'")
            ->implode(" \\\n  ");

        $this->line("curl -X POST '{$result['url']}' \\\n  -H 'Content-Type: application/json' \\\n  {$headerFlags} \\\n  -d '{$body}'");
    }
}
