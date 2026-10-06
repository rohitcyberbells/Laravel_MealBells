<?php

namespace App\Actions\Hrms;

use App\Models\Company;
use App\Models\Employee;
use App\Services\Hrms\HrmsConnectionResolver;
use App\Services\MealCalendar;
use App\Services\MealCutoff;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Builds and optionally sends a correctly signed webhook, as the vendor would.
 *
 * Shared by hrms:simulate and the company admin connect screen so there is one
 * implementation of the signing rules - if the two diverged, a passing test
 * button would stop meaning the real endpoint works.
 *
 * Two transports, because the two callers sit on different sides of the server:
 *
 *   'in_process' - the request is handed to the application directly. Used by
 *                  the connect screen, which runs INSIDE the web server: an
 *                  HTTP call to its own endpoint deadlocks a single-threaded
 *                  server like `artisan serve` until it times out.
 *   'http'       - a real network request, which is what the console command
 *                  wants: it is a separate process acting like the vendor, and
 *                  can be pointed at another host with --url.
 *
 * Both sign identically and go through the full middleware stack, so
 * VerifyHrmsSignature runs either way and the receiver needs no knowledge of
 * which was used.
 */
class SendTestHrmsEvent
{
    public function __construct(protected HrmsConnectionResolver $connections) {}

    /**
     * @param  array{event?: string, employee?: ?string, from?: ?string, to?: ?string, leave_type?: ?string, leave_id?: ?string, event_id?: ?string, url?: ?string, send?: bool}  $options
     * @return array{
     *     ok: bool,
     *     error: ?string,
     *     url: ?string,
     *     headers: array<string, string>,
     *     payload: array<string, mixed>,
     *     event_id: ?string,
     *     employee_matched: bool,
     *     status: ?int,
     *     body: ?string
     * }
     */
    public function execute(Company $company, array $options = []): array
    {
        $webhook = $this->connections->webhookFor($company);

        if (! $webhook) {
            return $this->failure('No webhook secret is configured for this company.');
        }

        $eventString = $options['event'] ?? 'leave_approved';
        $isCancellation = ($this->verbFor($company, $eventString)['action'] ?? null) === 'cancelled';
        $employeeReference = $options['employee'] ?? null;

        if (! $isCancellation && ! $employeeReference) {
            return $this->failure('An employee reference is required for an approval event.');
        }

        $payload = $this->buildPayload($company, $options, $eventString, $isCancellation);
        $body = json_encode($payload);
        $timestamp = (string) now()->timestamp;
        $headers = $this->buildHeaders($webhook, $timestamp, $body);

        // url() rather than config('app.url'), so the endpoint tested is exactly
        // the one the connect screen shows the vendor. An explicit --url still
        // wins, for pointing a local run at another host.
        $path = "/api/hrms/{$company->code}/events";
        $url = empty($options['url'])
            ? url($path)
            : rtrim($options['url'], '/').$path;

        $result = [
            'ok' => true,
            'error' => null,
            'url' => $url,
            'headers' => $headers,
            'payload' => $payload,
            'event_id' => (string) Arr::get($payload, $this->paths($company)['event_id']),
            'employee_matched' => $isCancellation || $this->employeeExists($company, (string) $employeeReference),
            'status' => null,
            'body' => null,
        ];

        if (($options['send'] ?? true) === false) {
            return $result;
        }

        // 'http' stays the default so the console command behaves exactly as
        // before; the connect screen opts into the in-process transport.
        try {
            [$status, $responseBody] = ($options['transport'] ?? 'http') === 'in_process'
                ? $this->deliverInProcess($url, $headers, $body)
                : $this->deliverOverHttp($url, $headers, $body);
        } catch (\Throwable $e) {
            // A network transport can fail outright, and a sub-request can throw
            // before it produces a response. Either way the caller gets a result
            // to show rather than an exception to render.
            Log::warning("HRMS test event could not be delivered: {$e->getMessage()}");

            return [
                ...$result,
                'ok' => false,
                'error' => 'No response from the test event (detail: '.$e->getMessage().').',
            ];
        }

        $ok = $status >= 200 && $status < 300;

        return [
            ...$result,
            'ok' => $ok,
            'status' => $status,
            'body' => $responseBody,
            'error' => $ok ? null : "The endpoint returned {$status}.",
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{0: int, 1: string}
     */
    protected function deliverOverHttp(string $url, array $headers, string $body): array
    {
        $response = Http::withHeaders($headers)->withBody($body, 'application/json')->post($url);

        return [$response->status(), $response->body()];
    }

    /**
     * Hand the request straight to the application.
     *
     * The signed raw body is passed as the request content, so getContent()
     * returns the exact bytes the digest was computed over and the signature
     * check behaves the same as it would over the wire.
     *
     * @param  array<string, string>  $headers
     * @return array{0: int, 1: string}
     */
    protected function deliverInProcess(string $url, array $headers, string $body): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $subRequest = Request::create($url, 'POST', [], [], [], $server, $body);

        // A sub-request rebinds the container's request, which would otherwise
        // leave the outer request pointing at it for the rest of this cycle.
        $outerRequest = app()->bound('request') ? app('request') : null;

        try {
            $response = app()->handle($subRequest);

            return [$response->getStatusCode(), (string) $response->getContent()];
        } finally {
            if ($outerRequest) {
                app()->instance('request', $outerRequest);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function buildPayload(Company $company, array $options, string $eventString, bool $isCancellation): array
    {
        $paths = $this->paths($company);
        $payload = [];

        Arr::set($payload, $paths['event_id'], $options['event_id'] ?? 'sim_'.Str::lower(Str::random(10)));
        Arr::set($payload, $paths['event_type'], $eventString);
        Arr::set($payload, $paths['occurred_at'], now()->toIso8601String());
        Arr::set($payload, $paths['leave_id'], $options['leave_id'] ?? 'SIM-'.Str::upper(Str::random(6)));

        // A cancellation only needs the leave reference: the skips it releases
        // are found through skips.external_ref.
        if ($isCancellation) {
            return $payload;
        }

        $from = $options['from'] ?? $this->nextOpenMealDay($company);
        $to = $options['to'] ?? $from;

        Arr::set($payload, $paths['employee_ref'], (string) $options['employee']);
        Arr::set($payload, $paths['from_date'], $from);
        Arr::set($payload, $paths['to_date'], $to);
        Arr::set($payload, $paths['reason'], 'Test event from MealBells');

        if (! empty($options['leave_type'])) {
            Arr::set($payload, $paths['leave_type'], $options['leave_type']);
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
     * The first day the engine will still accept: today when its cutoff is open,
     * otherwise the next meal day. Without this a test easily lands on a weekend
     * or a closed cutoff and reports nothing happened, which reads as a broken
     * integration rather than a correct refusal.
     */
    public function nextOpenMealDay(Company $company): string
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

    protected function employeeExists(Company $company, string $reference): bool
    {
        if ($reference === '') {
            return false;
        }

        return Employee::where('company_id', $company->id)
            ->where(fn ($query) => $query->where('external_id', $reference)
                ->orWhereRaw('UPPER(employee_code) = ?', [strtoupper(trim($reference))]))
            ->exists();
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

    /**
     * @return array<string, mixed>
     */
    protected function failure(string $error): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'url' => null,
            'headers' => [],
            'payload' => [],
            'event_id' => null,
            'employee_matched' => false,
            'status' => null,
            'body' => null,
        ];
    }
}
