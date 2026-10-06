<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class HrmsSimulateCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected string $secret = 'whsec_sim';

    protected function setUp(): void
    {
        parent::setUp();

        // Pins url() so the asserted endpoint is deterministic.
        URL::forceRootUrl('http://mealbells.test');

        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        $admin = User::create([
            'name' => 'Admin A', 'email' => 'admin@a.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyA->id,
        ]);

        CompanySetting::create([
            'company_id' => $this->companyA->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            'primary_admin_id' => $admin->id,
        ]);

        Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP101', 'external_id' => 'HR-1',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        config()->set("hrms.companies.{$this->companyA->id}", [
            'webhook' => ['auth' => 'signature', 'secret' => $this->secret],
        ]);
    }

    public function test_it_posts_a_signature_the_middleware_would_accept(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Event accepted.'], 202)]);

        $this->artisan('hrms:simulate', [
            '--company' => 'ALPHA1',
            '--employee' => 'HR-1',
            '--from' => '2026-10-06',
            '--event-id' => 'sim-1',
        ])->assertExitCode(0);

        Http::assertSent(function ($request) {
            $timestamp = $request->header('X-Hrms-Timestamp')[0];
            $expected = hash_hmac('sha256', $timestamp.'.'.$request->body(), $this->secret);

            // The digest must be over the exact bytes sent, which is the same
            // rule VerifyHrmsSignature applies on the way in.
            return $request->url() === 'http://mealbells.test/api/hrms/ALPHA1/events'
                && $request->header('X-Hrms-Signature')[0] === $expected;
        });
    }

    public function test_it_builds_the_canonical_envelope(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        $this->artisan('hrms:simulate', [
            '--company' => 'ALPHA1',
            '--employee' => 'HR-1',
            '--from' => '2026-10-06',
            '--to' => '2026-10-07',
            '--leave-id' => 'L-9',
            '--event-id' => 'sim-2',
        ])->assertExitCode(0);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['event_id'] === 'sim-2'
                && $body['event_type'] === 'leave_approved'
                && $body['leave']['id'] === 'L-9'
                && $body['leave']['employee_id'] === 'HR-1'
                && $body['leave']['from_date'] === '2026-10-06'
                && $body['leave']['to_date'] === '2026-10-07';
        });
    }

    public function test_it_honours_a_custom_payload_map(): void
    {
        config()->set("hrms.companies.{$this->companyA->id}.payload_map", [
            'event_id' => 'id',
            'event_type' => 'type',
            'occurred_at' => 'ts',
            'leave_id' => 'data.leave_ref',
            'employee_ref' => 'data.emp.code',
            'from_date' => 'data.start',
            'to_date' => 'data.end',
            'leave_type' => 'data.category',
            'reason' => 'data.note',
        ]);

        Http::fake(['*' => Http::response([], 202)]);

        $this->artisan('hrms:simulate', [
            '--company' => 'ALPHA1',
            '--employee' => 'HR-1',
            '--from' => '2026-10-06',
            '--event-id' => 'sim-3',
        ])->assertExitCode(0);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            // Built from the vendor's own shape, so --print output can be handed
            // to that vendor verbatim.
            return $body['id'] === 'sim-3'
                && $body['data']['emp']['code'] === 'HR-1'
                && $body['data']['start'] === '2026-10-06'
                && ! isset($body['event_id']);
        });
    }

    public function test_cancellation_sends_only_the_leave_reference(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        $this->artisan('hrms:simulate', [
            '--company' => 'ALPHA1',
            '--event' => 'leave_cancelled',
            '--leave-id' => 'L-9',
            '--event-id' => 'sim-4',
        ])->assertExitCode(0);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['leave'] === ['id' => 'L-9'] && ! isset($body['leave']['employee_id']);
        });
    }

    public function test_token_mode_sends_a_bearer_token_instead(): void
    {
        config()->set("hrms.companies.{$this->companyA->id}.webhook.auth", 'token');
        Http::fake(['*' => Http::response([], 202)]);

        $this->artisan('hrms:simulate', [
            '--company' => 'ALPHA1', '--employee' => 'HR-1', '--from' => '2026-10-06',
        ])->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === "Bearer {$this->secret}"
            && $request->header('X-Hrms-Signature') === []);
    }

    public function test_print_mode_sends_nothing(): void
    {
        Http::fake();

        $this->artisan('hrms:simulate', [
            '--company' => 'ALPHA1', '--employee' => 'HR-1', '--from' => '2026-10-06', '--print' => true,
        ])->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_it_defaults_to_the_next_day_the_count_is_still_open(): void
    {
        // Frozen clock is Mon 2026-10-05 08:00, before the 11:00 cutoff.
        Http::fake(['*' => Http::response([], 202)]);

        $this->artisan('hrms:simulate', [
            '--company' => 'ALPHA1', '--employee' => 'HR-1', '--event-id' => 'sim-5',
        ])->assertExitCode(0);

        Http::assertSent(fn ($request) => json_decode($request->body(), true)['leave']['from_date'] === '2026-10-05');
    }

    public function test_it_skips_past_a_closed_cutoff_and_the_weekend(): void
    {
        // Friday 09 at 12:00, after the 11:00 cutoff, so the next open meal day
        // is Monday 12 rather than Saturday.
        Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00', 'Asia/Kolkata'));
        Http::fake(['*' => Http::response([], 202)]);

        $this->artisan('hrms:simulate', [
            '--company' => 'ALPHA1', '--employee' => 'HR-1', '--event-id' => 'sim-6',
        ])->assertExitCode(0);

        Http::assertSent(fn ($request) => json_decode($request->body(), true)['leave']['from_date'] === '2026-10-12');
    }

    public function test_it_fails_without_a_configured_secret(): void
    {
        config()->set("hrms.companies.{$this->companyA->id}", []);
        Http::fake();

        $this->artisan('hrms:simulate', [
            '--company' => 'ALPHA1', '--employee' => 'HR-1',
        ])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_fails_on_an_unknown_company_code(): void
    {
        Http::fake();

        $this->artisan('hrms:simulate', ['--company' => 'NOPE99', '--employee' => 'HR-1'])
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_approval_without_an_employee_is_refused(): void
    {
        Http::fake();

        $this->artisan('hrms:simulate', ['--company' => 'ALPHA1'])->assertExitCode(1);

        Http::assertNothingSent();
    }
}
