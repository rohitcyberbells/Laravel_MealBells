<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Actions\Hrms\PullHrmsLeaves;
use App\Actions\Hrms\SendTestHrmsEvent;
use App\Http\Controllers\Controller;
use App\Models\CompanyHrmsConnection;
use App\Models\Employee;
use App\Models\HrmsWebhookEvent;
use App\Rules\SafeHrmsBaseUrl;
use App\Services\Hrms\Adapters\GenericHrmsAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
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
            // Whether a credential exists and where it points, never the
            // password or the cached token.
            'pull' => [
                'base_url' => $connection?->pull_base_url,
                'email' => $connection?->pull_email,
                'has_password' => (bool) $connection?->pull_password,
                'adapter' => $connection?->pull_adapter,
                'last_pull_at' => $connection?->last_pull_at?->toDateTimeString(),
                'last_pull_status' => $connection?->last_pull_status,
                'last_pull_error' => $connection?->last_pull_error,
                'adapters' => array_keys(config('hrms.pull_adapters', [])),
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
            'pull_test' => session('pull_test'),
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

    /**
     * Save the credentials hrms:pull signs in with.
     *
     * The password is write-only: it is never sent to the page, and leaving the
     * field blank keeps the stored one rather than clearing it, so saving a
     * change to the URL does not silently break the connection.
     */
    public function savePullConnection(Request $request)
    {
        $company = $this->company();

        $validated = $request->validate([
            // Scheme and host are judged by SafeHrmsBaseUrl: https in
            // production because this carries a password, and no private or
            // internal address, because the server then makes requests to
            // whatever is entered here.
            'base_url' => ['required', 'string', 'max:255', new SafeHrmsBaseUrl],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
            // From the configured list, so a form post cannot name a class.
            'adapter' => ['required', 'string', Rule::in(array_keys(config('hrms.pull_adapters', [])))],
        ]);

        $connection = CompanyHrmsConnection::firstOrNew(['company_id' => $company->id]);

        $urlChanged = $connection->pull_base_url !== $validated['base_url'];
        $emailChanged = $connection->pull_email !== $validated['email'];

        $connection->fill([
            'pull_base_url' => $validated['base_url'],
            'pull_email' => $validated['email'],
            'pull_adapter' => $validated['adapter'],
        ]);

        if (! empty($validated['password'])) {
            $connection->pull_password = $validated['password'];
        }

        // Any credential change invalidates the cached token: it was issued for
        // the old identity, or by the old host.
        if ($urlChanged || $emailChanged || ! empty($validated['password'])) {
            $connection->pull_token = null;
            $connection->pull_token_expires_at = null;
        }

        if (! $connection->pull_password) {
            return back()->withErrors(['password' => 'A password is required the first time you save this connection.']);
        }

        $connection->save();

        return back()->with('message', 'HRMS pull connection saved.');
    }

    /**
     * Fetch from the HR system and report what a real run would do, writing
     * nothing. The safe way to check credentials before letting the scheduler
     * touch anyone's meals.
     */
    public function testPullConnection(PullHrmsLeaves $pull)
    {
        $company = $this->company();

        try {
            $summary = $pull->execute($company, dryRun: true);
        } catch (Throwable $e) {
            report($e);

            return back()->with('pull_test', [
                'ok' => false,
                'error' => 'The test could not complete (detail: '.$e->getMessage().').',
            ]);
        }

        return back()->with('pull_test', [
            'ok' => $summary['ok'],
            'error' => $summary['error'],
            'fetched' => $summary['fetched'],
            'approved_future' => $summary['approved_future'],
            'applied' => $summary['applied'],
            'cancelled' => $summary['cancelled'],
            'ignored' => $summary['ignored'],
            'unknown_employee' => $summary['unknown_employee'],
            'unreadable' => $summary['unreadable'],
            'warnings' => $summary['warnings'],
        ]);
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
