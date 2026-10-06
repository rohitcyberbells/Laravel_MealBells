<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Employee;
use App\Models\HrmsWebhookEvent;
use App\Services\MealCalendar;
use App\Services\MealCutoff;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sends a correctly signed webhook to this application, as the vendor would.
 *
 * Useful for two things: demonstrating the integration without a live HRMS, and
 * onboarding a real one - `--print` emits the exact request to hand over, built
 * from that company's own payload_map rather than a hardcoded shape.
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

    public function handle(): int
    {
        $company = $this->resolveCompany();

        if (! $company) {
            return self::FAILURE;
        }

        $webhook = array_merge(
            config('hrms.webhook_defaults', []),
            config("hrms.companies.{$company->id}.webhook") ?? []
        );

        if (empty($webhook['secret'])) {
            $this->error("Company {$company->id} ({$company->code}) has no webhook secret in config/hrms.php.");

            return self::FAILURE;
        }

        $eventString = (string) $this->option('event');
        $isCancellation = ($this->verbFor($company, $eventString)['action'] ?? null) === 'cancelled';

        if (! $isCancellation && ! $this->option('employee')) {
            $this->error('--employee is required for an approval event.');

            return self::FAILURE;
        }

        $payload = $this->buildPayload($company, $eventString, $isCancellation);
        $body = json_encode($payload);
        $timestamp = (string) now()->timestamp;
        $headers = $this->buildHeaders($webhook, $timestamp, $body);

        $url = rtrim($this->option('url') ?: config('app.url'), '/')."/api/hrms/{$company->code}/events";

        if ($this->option('print')) {
            $this->printRequest($url, $headers, $body);

            return self::SUCCESS;
        }

        $this->warnAboutUnknownEmployee($company, $isCancellation);

        $response = Http::withHeaders($headers)
            ->withBody($body, 'application/json')
            ->post($url);

        $this->line("HTTP {$response->status()} {$response->body()}");

        if ($response->failed()) {
            return self::FAILURE;
        }

        $this->reportOutcome($company, (string) Arr::get($payload, $this->paths($company)['event_id']));

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

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(Company $company, string $eventString, bool $isCancellation): array
    {
        $paths = $this->paths($company);
        $payload = [];

        Arr::set($payload, $paths['event_id'], $this->option('event-id') ?: 'sim_'.Str::lower(Str::random(10)));
        Arr::set($payload, $paths['event_type'], $eventString);
        Arr::set($payload, $paths['occurred_at'], now()->toIso8601String());
        Arr::set($payload, $paths['leave_id'], $this->option('leave-id') ?: 'SIM-'.Str::upper(Str::random(6)));

        // A cancellation only needs the leave reference: the skips it released
        // are found through skips.external_ref.
        if ($isCancellation) {
            return $payload;
        }

        $from = $this->option('from') ?: $this->nextOpenMealDay($company);
        $to = $this->option('to') ?: $from;

        Arr::set($payload, $paths['employee_ref'], (string) $this->option('employee'));
        Arr::set($payload, $paths['from_date'], $from);
        Arr::set($payload, $paths['to_date'], $to);
        Arr::set($payload, $paths['reason'], 'Simulated via hrms:simulate');

        if ($leaveType = $this->option('leave-type')) {
            Arr::set($payload, $paths['leave_type'], $leaveType);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $webhook
     * @return array<string, string>
     */
    protected function buildHeaders(array $webhook, string $timestamp, string $body): array
    {
        if (($webhook['auth'] ?? 'signature') === 'token') {
            return ['Authorization' => 'Bearer '.$webhook['secret']];
        }

        return [
            $webhook['signature_header'] => hash_hmac('sha256', $timestamp.'.'.$body, (string) $webhook['secret']),
            $webhook['timestamp_header'] => $timestamp,
        ];
    }

    /**
     * The first day whose count the engine will still accept: today if its
     * cutoff has not passed, otherwise the next meal day. Keeps a demo from
     * landing on a weekend and reporting nothing happened.
     */
    protected function nextOpenMealDay(Company $company): string
    {
        $timezone = $company->setting?->timezone ?? config('mealbells.default_timezone', 'Asia/Kolkata');
        $cursor = Carbon::today($timezone);

        if (MealCutoff::hasCutoffPassed($company, $cursor->toDateString())) {
            $cursor->addDay();
        }

        for ($i = 0; $i < 14; $i++) {
            if (MealCalendar::isMealDay($company, $cursor->toDateString())) {
                return $cursor->toDateString();
            }

            $cursor->addDay();
        }

        return $cursor->toDateString();
    }

    protected function warnAboutUnknownEmployee(Company $company, bool $isCancellation): void
    {
        if ($isCancellation) {
            return;
        }

        $reference = (string) $this->option('employee');

        $exists = Employee::where('company_id', $company->id)
            ->where(fn ($q) => $q->where('external_id', $reference)
                ->orWhereRaw('UPPER(employee_code) = ?', [strtoupper(trim($reference))]))
            ->exists();

        if (! $exists) {
            $this->warn("No employee in this company matches '{$reference}'; the event will be recorded as blocked.");
        }
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
     * @param  array<string, string>  $headers
     */
    protected function printRequest(string $url, array $headers, string $body): void
    {
        $this->line("POST {$url}");

        foreach ($headers as $name => $value) {
            $this->line("{$name}: {$value}");
        }

        $this->line('Content-Type: application/json');
        $this->newLine();
        $this->line($body);
        $this->newLine();

        $headerFlags = collect($headers)
            ->map(fn ($value, $name) => "-H '{$name}: {$value}'")
            ->implode(" \\\n  ");

        $this->line("curl -X POST '{$url}' \\\n  -H 'Content-Type: application/json' \\\n  {$headerFlags} \\\n  -d '{$body}'");
    }

    /**
     * @return array<string, string>
     */
    protected function paths(Company $company): array
    {
        return array_merge(
            config('hrms.payload_defaults', []),
            config("hrms.companies.{$company->id}.payload_map") ?? []
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function verbFor(Company $company, string $eventString): array
    {
        $map = array_merge(
            config('hrms.event_type_defaults', []),
            config("hrms.companies.{$company->id}.event_type_map") ?? []
        );

        return $map[$eventString] ?? [];
    }
}
