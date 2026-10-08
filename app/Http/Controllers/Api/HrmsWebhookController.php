<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessHrmsLeaveEvent;
use App\Models\Company;
use App\Models\HrmsWebhookEvent;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class HrmsWebhookController extends Controller
{
    /**
     * Receive one HRMS leave event.
     *
     * Authenticates, de-duplicates and records, then hands off. No meal logic
     * runs here by design: the event is stored as 'received', queued, and
     * acknowledged, so a slow apply can never cost us the delivery.
     */
    public function store(Request $request, Company $company): JsonResponse
    {
        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            return response()->json(['message' => 'Empty or invalid JSON body.'], 422);
        }

        $paths = array_merge(
            config('hrms.payload_defaults', []),
            config("hrms.companies.{$company->id}.payload_map", []) ?? []
        );

        $externalEventId = trim((string) Arr::get($payload, $paths['event_id'], ''));
        $eventType = trim((string) Arr::get($payload, $paths['event_type'], ''));

        // Without an event id there is no way to de-duplicate, and a vendor that
        // retries would apply the same leave twice. Refuse rather than guess.
        if ($externalEventId === '' || $eventType === '') {
            return response()->json(['message' => 'Payload is missing event_id or event_type.'], 422);
        }

        try {
            // Wrapped in a transaction of its own so that the unique violation
            // below rolls back a savepoint rather than everything around it.
            //
            // On SQLite a failed INSERT leaves the enclosing transaction usable,
            // so catching the violation was enough. PostgreSQL aborts the whole
            // transaction instead - every later statement fails with 25P02 -
            // and this is the production driver. Nested here, Laravel issues a
            // SAVEPOINT, so the duplicate is swallowed and the caller's
            // transaction survives.
            $event = DB::transaction(fn () => HrmsWebhookEvent::create([
                'company_id' => $company->id,
                'external_event_id' => $externalEventId,
                'event_type' => $eventType,
                'leave_external_id' => $this->optionalString($payload, $paths['leave_id'] ?? null),
                'occurred_at' => $this->parseOccurredAt($payload, $paths['occurred_at'] ?? null),
                'payload' => $payload,
                'status' => 'received',
            ]));
        } catch (QueryException) {
            // unique(company_id, external_event_id) tripped: a retried delivery of
            // an event already held. Acknowledging with 200 stops the vendor
            // retrying forever, and nothing is recorded or applied twice.
            return response()->json([
                'message' => 'Event already received.',
                'event_id' => $externalEventId,
                'duplicate' => true,
            ], 200);
        }

        // All the work happens in the job: vendors time out after a few seconds
        // and retry anything slow, so the delivery is acknowledged immediately.
        ProcessHrmsLeaveEvent::dispatch($event->id);

        return response()->json([
            'message' => 'Event accepted.',
            'event_id' => $event->external_event_id,
            'duplicate' => false,
        ], 202);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function optionalString(array $payload, ?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $value = Arr::get($payload, $path);

        return $value !== null && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function parseOccurredAt(array $payload, ?string $path): ?string
    {
        $raw = $this->optionalString($payload, $path);

        if (! $raw) {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateTimeString();
        } catch (\Throwable) {
            // A malformed timestamp must not reject the delivery; it only costs
            // the out-of-order protection for this one event.
            return null;
        }
    }
}
