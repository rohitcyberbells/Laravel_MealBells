<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Actions\Hrms\SendTestHrmsEvent;
use App\Http\Controllers\Controller;
use App\Models\CompanyHrmsConnection;
use App\Models\Employee;
use App\Models\HrmsWebhookEvent;
use App\Services\Hrms\Adapters\GenericHrmsAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Throwable;

class CompanyHrmsController extends Controller
{
    public function index()
    {
        $company = $this->company();

        $connection = CompanyHrmsConnection::where('company_id', $company->id)->first();

        $adapterClass = config("hrms.companies.{$company->id}.adapter")
            ?? config('hrms.default_adapter', GenericHrmsAdapter::class);

        return Inertia::render('CompanyAdmin/Hrms/Index', [
            'company' => $company->only(['id', 'name', 'code']),
            'connection' => [
                'webhook_url' => url("/api/hrms/{$company->code}/events"),
                // Whether a secret exists, never the secret itself.
                'has_secret' => (bool) $connection?->webhook_secret,
                'secret_rotated_at' => $connection?->secret_rotated_at?->toDateTimeString(),
                'adapter' => app($adapterClass)->name(),
                'signature_header' => config('hrms.webhook_defaults.signature_header'),
                'timestamp_header' => config('hrms.webhook_defaults.timestamp_header'),
                'tolerance_seconds' => config('hrms.webhook_defaults.tolerance_seconds'),
                'max_body_bytes' => config('hrms.max_body_bytes'),
            ],
            'events' => $this->recentEvents($company->id),
            'employee_hints' => Employee::where('company_id', $company->id)
                ->where('status', 'active')
                ->whereNotNull('external_id')
                ->orderBy('name')
                ->limit(10)
                ->get(['employee_code', 'external_id', 'name']),
            // Flash, so it survives exactly one render after rotating.
            'new_secret' => session('new_secret'),
            'test_result' => session('test_result'),
        ]);
    }

    public function rotateSecret(Request $request)
    {
        $company = $this->company();
        $secret = CompanyHrmsConnection::generateSecret();

        CompanyHrmsConnection::updateOrCreate(
            ['company_id' => $company->id],
            [
                'webhook_secret' => $secret,
                'secret_rotated_at' => now(),
                'rotated_by' => $request->user()->id,
            ]
        );

        // Shown once. The plaintext is never stored, so it cannot be recovered -
        // rotating again is the only way forward.
        return back()->with('new_secret', $secret);
    }

    public function sendTestEvent(Request $request, SendTestHrmsEvent $sender)
    {
        $company = $this->company();

        $validated = $request->validate([
            'event' => 'nullable|string|max:100',
            'employee' => 'nullable|string|max:255',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);

        try {
            $result = $sender->execute($company, [
                'event' => $validated['event'] ?? 'leave_approved',
                'employee' => $validated['employee'] ?? null,
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
                // In-process, because this runs inside the web server: calling
                // our own endpoint over HTTP deadlocks a single-threaded server
                // until it times out.
                'transport' => 'in_process',
            ]);
        } catch (Throwable $e) {
            // Nothing should reach here - the action already converts a failed
            // delivery into a result - but the page must not break if it does.
            report($e);

            return back()->with('test_result', [
                'ok' => false,
                'error' => 'No response from the test event (detail: '.$e->getMessage().').',
                'status' => null,
                'event_id' => null,
                'employee_matched' => false,
                'event_status' => null,
                'summary' => null,
            ]);
        }

        // What the event actually did, so the message can say applied, blocked
        // or still queued rather than only that it was accepted.
        $event = $result['event_id']
            ? HrmsWebhookEvent::where('company_id', $company->id)
                ->where('external_event_id', $result['event_id'])
                ->first()
            : null;

        return back()->with('test_result', [
            'ok' => $result['ok'],
            'error' => $result['error'],
            'status' => $result['status'],
            'event_id' => $result['event_id'],
            'employee_matched' => $result['employee_matched'],
            'event_status' => $event?->status,
            'summary' => $event ? $this->summarise($event) : null,
        ]);
    }

    /**
     * Last 20 events for this company, flattened for display.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function recentEvents(int $companyId): array
    {
        return HrmsWebhookEvent::where('company_id', $companyId)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (HrmsWebhookEvent $event) => [
                'id' => $event->id,
                'external_event_id' => $event->external_event_id,
                'event_type' => $event->event_type,
                'status' => $event->status,
                'received_at' => $event->created_at?->toDateTimeString(),
                'processed_at' => $event->processed_at?->toDateTimeString(),
                'applied_days' => $event->result['applied_days'] ?? [],
                'already_days' => $event->result['already_days'] ?? [],
                'released_days' => $event->result['released_days'] ?? [],
                'blocked_days' => collect($event->result['blocked_days'] ?? [])
                    ->merge($event->result['release_blocked'] ?? [])
                    ->all(),
                'notes' => $event->result['notes'] ?? [],
                'error' => $event->error,
            ])
            ->all();
    }

    /**
     * A one-line account of what the event did, for the message above the list.
     */
    protected function summarise(HrmsWebhookEvent $event): string
    {
        $result = $event->result ?? [];

        $parts = [];

        foreach (['applied_days' => 'applied', 'already_days' => 'already skipped', 'released_days' => 'released'] as $key => $label) {
            if ($days = $result[$key] ?? []) {
                $parts[] = count($days).' '.$label.' ('.implode(', ', $days).')';
            }
        }

        foreach (collect($result['blocked_days'] ?? [])->merge($result['release_blocked'] ?? []) as $blocked) {
            $parts[] = "{$blocked['date']} blocked ({$blocked['reason']})";
        }

        foreach ($result['notes'] ?? [] as $note) {
            $parts[] = $note;
        }

        return $parts ? implode(' · ', $parts) : 'Nothing to change.';
    }

    protected function company()
    {
        $company = Auth::user()->company;

        abort_if(! $company, 404, 'Company not found.');

        return $company;
    }
}
