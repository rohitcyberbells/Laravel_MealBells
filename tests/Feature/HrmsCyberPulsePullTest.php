<?php

namespace Tests\Feature;

use App\Actions\Hrms\PullHrmsLeaves;
use App\Actions\Meal\CancelSkip;
use App\Actions\Meal\RecordSkip;
use App\Enums\SkipOutcome;
use App\Models\Company;
use App\Models\CompanyCalendarDay;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\HrmsWebhookEvent;
use App\Models\Skip;
use App\Models\User;
use App\Services\Hrms\CyberPulse\CyberPulseClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The CyberPulse pull, driven entirely from fixtures.
 *
 * Http::preventStrayRequests() is on in every test, so a call to the real HRMS
 * fails the test rather than reaching a live system.
 *
 * The clock is frozen at Monday 2026-10-05 08:00 IST by TestCase, so "tomorrow"
 * is 2026-10-06 throughout and every fixture date is a weekday.
 */
class HrmsCyberPulsePullTest extends TestCase
{
    use RefreshDatabase;

    protected const BASE = 'https://hrms.cyberpulse.test';

    protected Company $company;

    protected CompanyHrmsConnection $connection;

    protected Employee $alice;

    protected Employee $bob;

    protected Employee $cara;

    /** @var array<int, array<string, mixed>>|null */
    protected ?array $vendorRows = null;

    protected int $fetchStatus = 200;

    protected int $loginStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->registerVendorFake();

        // These tests assert on Inertia props, and an SSR render returns a
        // string instead - and would be served by the vendor fake above, since
        // the SSR call is just another HTTP request.
        config()->set('inertia.ssr.enabled', false);

        $this->company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        User::create([
            'name' => 'Alpha HR', 'email' => 'hr@alpha.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        // Alice is known by the vendor's id, Bob and Cara only by address - so
        // Bob and Cara exercise the email fallback and the id backfill.
        $this->alice = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'EMP101',
            'external_id' => '66e0bb0000000000000000e1', 'email' => 'alice@alpha.test',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->bob = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'EMP102',
            'external_id' => null, 'email' => 'bob@alpha.test',
            'name' => 'Bob', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->cara = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'EMP103',
            'external_id' => null, 'email' => 'cara@alpha.test',
            'name' => 'Cara', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->connection = CompanyHrmsConnection::create([
            'company_id' => $this->company->id,
            'pull_base_url' => self::BASE,
            'pull_email' => 'integration@alpha.test',
            'pull_password' => 'vendor-secret',
            'pull_adapter' => 'cyberpulse',
        ]);
    }

    /** @return array<string, mixed> */
    protected function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/CyberPulse/fetchAll.json')), true);
    }

    /**
     * The vendor is faked once, in setUp, reading these three properties.
     *
     * Registering a second Http::fake() would merge with the first and the
     * earlier stub would keep winning, so a run meant to see a changed fetch
     * would quietly get the original one.
     */
    protected function fakeVendor(?array $rows = null, int $fetchStatus = 200, int $loginStatus = 200): void
    {
        $this->vendorRows = $rows;
        $this->fetchStatus = $fetchStatus;
        $this->loginStatus = $loginStatus;
    }

    protected function registerVendorFake(): void
    {
        Http::fake([
            self::BASE.'/api/employee/login' => function () {
                return Http::response(
                    $this->loginStatus === 200
                        ? ['token' => 'jwt-from-vendor', 'employee' => ['_id' => 'admin']]
                        : ['message' => 'nope'],
                    $this->loginStatus,
                );
            },
            self::BASE.'/api/leave/fetchAll' => function () {
                if ($this->fetchStatus !== 200) {
                    return Http::response(['message' => 'upstream error'], $this->fetchStatus);
                }

                $body = $this->fixture();

                if ($this->vendorRows !== null) {
                    $body['data'] = $this->vendorRows;
                }

                return Http::response($body, 200);
            },
        ]);
    }

    protected function pull(bool $dryRun = false): array
    {
        return app(PullHrmsLeaves::class)->execute($this->company->fresh('setting'), $dryRun);
    }

    /** @return array<int, array<string, mixed>> */
    protected function onlyLeaves(string ...$ids): array
    {
        return array_values(array_filter(
            $this->fixture()['data'],
            fn ($row) => in_array($row['_id'], $ids, true),
        ));
    }

    // ---------------------------------------------------------------- applying

    public function test_an_approved_leave_becomes_skips_on_its_meal_days(): void
    {
        $this->fakeVendor();

        $summary = $this->pull();

        $this->assertTrue($summary['ok']);
        $this->assertEquals('ok', $summary['status']);

        // Alice's casual leave covers the 8th and the 9th, both weekdays.
        $this->assertEquals(
            ['2026-10-08', '2026-10-09'],
            Skip::where('employee_id', $this->alice->id)->orderBy('date')->pluck('date')
                ->map(fn ($d) => Carbon::parse($d)->toDateString())->all(),
        );

        $skip = Skip::where('employee_id', $this->alice->id)->first();
        $this->assertEquals('leave', $skip->source);
        $this->assertEquals('cp:leave:66f1aa0000000000000000a1', $skip->external_ref);
    }

    public function test_the_fixture_is_filtered_down_to_what_is_actionable(): void
    {
        $this->fakeVendor();

        $summary = $this->pull();

        $this->assertEquals(7, $summary['fetched']);
        // Approved and ending tomorrow or later: Alice's leave, Bob's WFH,
        // Cara's half day, and the unknown employee's leave.
        $this->assertEquals(4, $summary['approved_future']);
        $this->assertEquals(2, $summary['applied']);
        $this->assertEquals(1, $summary['ignored']);
        $this->assertEquals(1, $summary['unknown_employee']);
    }

    public function test_a_wfh_leave_becomes_a_wfh_skip(): void
    {
        $this->fakeVendor();
        $this->pull();

        $skip = Skip::where('employee_id', $this->bob->id)->sole();

        $this->assertEquals('wfh', $skip->source);
        $this->assertEquals('cp:leave:66f1aa0000000000000000a2', $skip->external_ref);
    }

    public function test_a_pending_or_rejected_leave_is_never_applied(): void
    {
        $this->fakeVendor();
        $this->pull();

        foreach (['66f1aa0000000000000000a4', '66f1aa0000000000000000a5'] as $id) {
            $this->assertDatabaseMissing('skips', ['external_ref' => "cp:leave:{$id}"]);
            $this->assertDatabaseMissing('hrms_webhook_events', ['leave_external_id' => "cp:leave:{$id}"]);
        }
    }

    public function test_status_casing_is_ignored_when_deciding_what_is_approved(): void
    {
        // 'Approved', 'approved', 'APPROVED' all mean the same thing.
        $rows = $this->onlyLeaves('66f1aa0000000000000000a1');
        $rows[0]['status'] = 'APPROVED';

        $this->fakeVendor($rows);
        $summary = $this->pull();

        $this->assertEquals(1, $summary['applied']);
    }

    public function test_a_leave_already_over_is_left_alone(): void
    {
        $this->fakeVendor();
        $this->pull();

        // The September leave is approved but finished; acting on it would be
        // editing a locked past day.
        $this->assertDatabaseMissing('skips', ['external_ref' => 'cp:leave:66f1aa0000000000000000a7']);
    }

    // ------------------------------------------------------------------- dates

    public function test_a_utc_midnight_instant_lands_on_that_calendar_day(): void
    {
        $rows = $this->onlyLeaves('66f1aa0000000000000000a1');
        $rows[0]['startDate'] = '2026-10-08T00:00:00.000Z';
        $rows[0]['endDate'] = '2026-10-08T00:00:00.000Z';

        $this->fakeVendor($rows);
        $this->pull();

        $this->assertEquals('2026-10-08', Carbon::parse(Skip::sole()->date)->toDateString());
    }

    /**
     * 18:30 UTC is IST-local midnight the next day. Reading it naively would put
     * the leave a day early.
     */
    public function test_an_ist_local_midnight_instant_lands_on_the_following_day(): void
    {
        $rows = $this->onlyLeaves('66f1aa0000000000000000a1');
        $rows[0]['startDate'] = '2026-10-07T18:30:00.000Z';
        $rows[0]['endDate'] = '2026-10-07T18:30:00.000Z';

        $this->fakeVendor($rows);
        $this->pull();

        $this->assertEquals('2026-10-08', Carbon::parse(Skip::sole()->date)->toDateString());
    }

    // ------------------------------------------------------- partial / unknown

    public function test_a_half_day_is_ignored_with_a_reason(): void
    {
        $this->fakeVendor($this->onlyLeaves('66f1aa0000000000000000a3'));
        $summary = $this->pull();

        $this->assertEquals(1, $summary['ignored']);
        $this->assertEquals(0, $summary['applied']);
        $this->assertDatabaseCount('skips', 0);

        $event = HrmsWebhookEvent::where('leave_external_id', 'cp:leave:66f1aa0000000000000000a3')->sole();
        $this->assertEquals(HrmsWebhookEvent::STATUS_IGNORED, $event->status);
        $this->assertStringContainsString('partial_day_not_supported', json_encode($event->result));
    }

    public function test_a_leave_for_an_unknown_employee_is_recorded_not_dropped(): void
    {
        $this->fakeVendor($this->onlyLeaves('66f1aa0000000000000000a6'));
        $summary = $this->pull();

        $this->assertEquals(1, $summary['unknown_employee']);
        $this->assertDatabaseCount('skips', 0);

        // Recorded so it shows on the Health page rather than vanishing.
        $event = HrmsWebhookEvent::where('leave_external_id', 'cp:leave:66f1aa0000000000000000a6')->sole();
        $this->assertEquals(HrmsWebhookEvent::STATUS_BLOCKED, $event->status);
    }

    public function test_an_unknown_leave_type_is_refused_rather_than_assumed_full_day(): void
    {
        $rows = $this->onlyLeaves('66f1aa0000000000000000a1');
        $rows[0]['leaveType'] = 'sabbatical';

        $this->fakeVendor($rows);
        $summary = $this->pull();

        $this->assertEquals(0, $summary['approved_future']);
        $this->assertDatabaseCount('skips', 0);
    }

    // --------------------------------------------------------- email matching

    public function test_an_email_match_saves_the_vendor_id_onto_the_employee(): void
    {
        $this->assertNull($this->bob->external_id);

        $this->fakeVendor();
        $this->pull();

        $this->assertEquals('66e0bb0000000000000000e2', $this->bob->fresh()->external_id);
    }

    // ------------------------------------------------------------ cancellation

    /**
     * Deleting a leave in CyberPulse removes the row outright, so its absence
     * from a later fetch is the only signal there is.
     */
    public function test_a_leave_that_disappeared_has_its_skips_cancelled(): void
    {
        $this->fakeVendor();
        $this->pull();
        $this->assertEquals(2, Skip::where('employee_id', $this->alice->id)->whereNull('cancelled_at')->count());

        // Second run: Alice's leave is gone, everything else still there.
        $remaining = array_values(array_filter(
            $this->fixture()['data'],
            fn ($row) => $row['_id'] !== '66f1aa0000000000000000a1',
        ));

        $this->fakeVendor($remaining);
        $summary = $this->pull();

        $this->assertEquals(1, $summary['cancelled']);
        $this->assertEquals(0, Skip::where('employee_id', $this->alice->id)->whereNull('cancelled_at')->count());
        $this->assertEquals(2, Skip::where('employee_id', $this->alice->id)->whereNotNull('cancelled_at')->count());
    }

    public function test_an_approved_leave_turned_rejected_has_its_skips_cancelled(): void
    {
        $this->fakeVendor();
        $this->pull();

        $rows = $this->fixture()['data'];

        foreach ($rows as $i => $row) {
            if ($row['_id'] === '66f1aa0000000000000000a1') {
                $rows[$i]['status'] = 'Rejected';
            }
        }

        $this->fakeVendor($rows);
        $summary = $this->pull();

        $this->assertEquals(1, $summary['cancelled']);
        $this->assertEquals(0, Skip::where('employee_id', $this->alice->id)->whereNull('cancelled_at')->count());
    }

    /**
     * The guarantee for HR: a skip someone entered by hand carries no
     * external_ref, so the integration cannot see it, let alone release it.
     */
    public function test_a_hand_entered_skip_is_never_cancelled(): void
    {
        $byHand = Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->alice->id,
            'date' => '2026-10-08', 'source' => 'hr', 'external_ref' => null,
        ]);

        $selfServe = Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->cara->id,
            'date' => '2026-10-09', 'source' => 'self', 'external_ref' => null,
        ]);

        // A fetch with nothing approved at all - the worst case for cancellation.
        $this->fakeVendor([]);
        $summary = $this->pull();

        $this->assertEquals(0, $summary['cancelled']);
        $this->assertNull($byHand->fresh()->cancelled_at);
        $this->assertNull($selfServe->fresh()->cancelled_at);
    }

    public function test_a_failed_fetch_cancels_nothing(): void
    {
        $this->fakeVendor();
        $this->pull();
        $held = Skip::whereNull('cancelled_at')->count();
        $this->assertGreaterThan(0, $held);

        $this->fakeVendor(fetchStatus: 500);
        $summary = $this->pull();

        $this->assertFalse($summary['ok']);
        $this->assertEquals(0, $summary['cancelled']);
        $this->assertEquals($held, Skip::whereNull('cancelled_at')->count());
        $this->assertNotEmpty(array_filter($summary['warnings'], fn ($w) => str_contains($w, 'nothing was cancelled')));
    }

    public function test_a_failed_login_cancels_nothing(): void
    {
        $this->fakeVendor();
        $this->pull();
        $held = Skip::whereNull('cancelled_at')->count();

        // Drop the cached token and make the next sign-in fail, so the run
        // cannot even reach the fetch.
        $this->connection->forceFill(['pull_token' => null, 'pull_token_expires_at' => null, 'last_login_at' => null])->save();
        $this->fakeVendor(loginStatus: 401);

        $summary = $this->pull();

        $this->assertFalse($summary['ok']);
        $this->assertEquals($held, Skip::whereNull('cancelled_at')->count());
    }

    /**
     * An implausibly large cancellation batch is the shape a mis-scoped or
     * truncated fetch takes. Being a few hours stale beats adding meals back for
     * people who are on leave.
     */
    public function test_a_cancellation_batch_over_the_share_limit_is_refused(): void
    {
        // Five leaves held, each on its own day.
        $refs = [];

        foreach (['2026-10-08', '2026-10-09', '2026-10-12', '2026-10-13', '2026-10-14'] as $i => $date) {
            $refs[] = $ref = "cp:leave:held-{$i}";
            Skip::create([
                'company_id' => $this->company->id, 'employee_id' => $this->alice->id,
                'date' => $date, 'source' => 'leave', 'external_ref' => $ref,
            ]);
        }

        // A fetch that reports none of them: 100% would be cancelled.
        $this->fakeVendor([]);
        $summary = $this->pull();

        $this->assertTrue($summary['ok'], 'the run completes, it just refuses to act');
        $this->assertEquals('suspicious', $summary['status']);
        $this->assertEquals(5, $summary['cancel_candidates']);
        $this->assertEquals(0, $summary['cancelled']);
        $this->assertEquals(5, Skip::whereNull('cancelled_at')->count());
        $this->assertNotEmpty(array_filter($summary['warnings'], fn ($w) => str_contains($w, 'Refused to cancel')));
    }

    public function test_a_cancellation_batch_inside_the_share_limit_proceeds(): void
    {
        // Ten held leaves, one of which vanishes: 10%, under the 30% limit.
        for ($i = 0; $i < 10; $i++) {
            Skip::create([
                'company_id' => $this->company->id, 'employee_id' => $this->alice->id,
                'date' => '2026-10-'.str_pad((string) (6 + $i), 2, '0', STR_PAD_LEFT),
                'source' => 'leave', 'external_ref' => "cp:leave:held-{$i}",
            ]);
        }

        // Report nine of them as still approved.
        $rows = [];

        for ($i = 1; $i < 10; $i++) {
            $rows[] = [
                '_id' => "held-{$i}",
                'employeeId' => ['_id' => '66e0bb0000000000000000e1', 'email' => 'alice@alpha.test', 'name' => 'Alice'],
                'startDate' => '2026-10-20T00:00:00.000Z',
                'endDate' => '2026-10-20T00:00:00.000Z',
                'leaveType' => 'casual',
                'status' => 'Approved',
            ];
        }

        $this->fakeVendor($rows);
        $summary = $this->pull();

        $this->assertEquals('ok', $summary['status']);
        $this->assertEquals(1, $summary['cancel_candidates']);
        $this->assertEquals(1, $summary['cancelled']);
        $this->assertNotNull(Skip::where('external_ref', 'cp:leave:held-0')->sole()->cancelled_at);
    }

    /**
     * A share alone cannot express "this looks like a mass withdrawal": holding
     * two leaves and losing one is already 50%. Without a floor the integration
     * would refuse every ordinary cancellation at small scale and silently stop
     * releasing meals.
     */
    public function test_a_routine_cancellation_is_allowed_even_though_it_is_a_large_share(): void
    {
        foreach (['cp:leave:one', 'cp:leave:two'] as $i => $ref) {
            Skip::create([
                'company_id' => $this->company->id, 'employee_id' => $this->alice->id,
                'date' => '2026-10-0'.(8 + $i), 'source' => 'leave', 'external_ref' => $ref,
            ]);
        }

        // Only one of the two is still approved: 50% would be cancelled.
        $this->fakeVendor([[
            '_id' => 'two',
            'employeeId' => ['_id' => '66e0bb0000000000000000e1', 'email' => 'alice@alpha.test', 'name' => 'Alice'],
            'startDate' => '2026-10-20T00:00:00.000Z',
            'endDate' => '2026-10-20T00:00:00.000Z',
            'leaveType' => 'casual',
            'status' => 'Approved',
        ]]);

        $summary = $this->pull();

        $this->assertEquals('ok', $summary['status']);
        $this->assertEquals(1, $summary['cancelled']);
        $this->assertNotNull(Skip::where('external_ref', 'cp:leave:one')->sole()->cancelled_at);
    }

    /**
     * The floor must not become a hole: once past it, the share decides again.
     */
    public function test_just_past_the_floor_the_share_guard_applies_again(): void
    {
        $floor = (int) config('hrms.cyberpulse.cancels_always_allowed');

        for ($i = 0; $i <= $floor; $i++) {
            Skip::create([
                'company_id' => $this->company->id, 'employee_id' => $this->alice->id,
                'date' => '2026-10-'.str_pad((string) (8 + $i), 2, '0', STR_PAD_LEFT),
                'source' => 'leave', 'external_ref' => "cp:leave:held-{$i}",
            ]);
        }

        // Every one of them gone: one more candidate than the floor allows.
        $this->fakeVendor([]);
        $summary = $this->pull();

        $this->assertEquals($floor + 1, $summary['cancel_candidates']);
        $this->assertEquals('suspicious', $summary['status']);
        $this->assertEquals(0, $summary['cancelled']);
        $this->assertEquals($floor + 1, Skip::whereNull('cancelled_at')->count());
    }

    // --------------------------------------------------------------- idempotency

    public function test_running_twice_changes_nothing_the_second_time(): void
    {
        $this->fakeVendor();
        $this->pull();

        $skips = Skip::orderBy('id')->get()->map(fn ($s) => [$s->employee_id, $s->date, $s->source])->all();
        $events = HrmsWebhookEvent::count();

        $this->fakeVendor();
        $second = $this->pull();

        $this->assertEquals(0, $second['applied'], 'nothing new to apply');
        $this->assertEquals(0, $second['cancelled']);
        $this->assertGreaterThan(0, $second['duplicate']);
        $this->assertEquals($events, HrmsWebhookEvent::count(), 'no duplicate event rows');
        $this->assertEquals($skips, Skip::orderBy('id')->get()->map(fn ($s) => [$s->employee_id, $s->date, $s->source])->all());
    }

    // ------------------------------------------------------------------ privacy

    public function test_no_field_outside_the_whitelist_is_ever_stored(): void
    {
        $this->fakeVendor();
        $this->pull();

        // Everything the fixture carries that we must not keep.
        $forbidden = [
            '000111222333', 'HDFC0001234', '1450000', '1994-03-11',
            '12 Example Road, Pune', 'ABCDE1234F', 'alice.jpg',
            'Dental surgery follow-up', 'Plumber visiting', 'first-half',
            'Engineering', '980000',
        ];

        // Every table, not just the ones we expect to have written.
        $tables = collect(DB::select("select name from sqlite_master where type='table'"))
            ->pluck('name')
            ->reject(fn ($t) => str_starts_with($t, 'sqlite_'));

        $dump = '';

        foreach ($tables as $table) {
            foreach (DB::table($table)->get() as $row) {
                $dump .= json_encode($row);
            }
        }

        foreach ($forbidden as $secret) {
            $this->assertStringNotContainsString($secret, $dump, "'{$secret}' reached the database");
        }
    }

    public function test_the_stored_payload_carries_only_the_canonical_envelope(): void
    {
        $this->fakeVendor();
        $this->pull();

        $payload = HrmsWebhookEvent::where('leave_external_id', 'cp:leave:66f1aa0000000000000000a1')
            ->sole()->payload;

        $this->assertEquals(
            ['event_id', 'event_type', 'occurred_at', 'leave'],
            array_keys($payload),
        );

        $this->assertEquals(
            ['id', 'employee_id', 'employee_email', 'from_date', 'to_date', 'type', 'reason'],
            array_keys($payload['leave']),
        );
    }

    public function test_the_whitelist_drops_unknown_fields_at_the_boundary(): void
    {
        $client = app(CyberPulseClient::class);

        $this->connection->forceFill(['pull_token' => 'tok', 'pull_token_expires_at' => now()->addDay()])->save();
        $this->fakeVendor();

        $result = $client->fetchLeaves($this->connection->fresh());

        $this->assertTrue($result->ok);

        foreach ($result->leaves as $leave) {
            $this->assertEmpty(
                array_diff(array_keys($leave), [...CyberPulseClient::LEAVE_FIELDS, 'employeeId']),
                'a leave field outside the whitelist survived',
            );
            $this->assertEmpty(
                array_diff(array_keys($leave['employeeId']), CyberPulseClient::EMPLOYEE_FIELDS),
                'an employee field outside the whitelist survived',
            );
        }
    }

    // -------------------------------------------------------------- token & login

    public function test_the_token_is_fetched_once_and_then_reused(): void
    {
        $this->fakeVendor();
        $this->pull();

        $this->assertEquals('jwt-from-vendor', $this->connection->fresh()->pull_token);
        Http::assertSentCount(2); // one login, one fetch

        // A second run already holds a token, so it must not log in again.
        $this->fakeVendor();
        $this->pull();

        Http::assertSentCount(3);
    }

    public function test_the_employee_directory_is_never_fetched(): void
    {
        $this->fakeVendor();
        $this->pull();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/employee/fetchAll'));
    }

    public function test_a_second_login_inside_the_cooldown_is_refused(): void
    {
        // A login just happened and we hold no token, so the next run would try
        // to sign in again straight away.
        $this->connection->forceFill([
            'pull_token' => null,
            'pull_token_expires_at' => null,
            'last_login_at' => now()->subSeconds(5),
        ])->save();

        $this->fakeVendor();
        $summary = $this->pull();

        $this->assertFalse($summary['ok']);
        $this->assertStringContainsString('Refusing to log in', (string) $summary['error']);
        Http::assertNothingSent();
    }

    public function test_a_failing_login_is_rate_limited_too(): void
    {
        $this->connection->forceFill(['pull_token' => null, 'pull_token_expires_at' => null, 'last_login_at' => null])->save();
        $this->fakeVendor(loginStatus: 401);

        $first = $this->pull();
        $this->assertFalse($first['ok']);

        // Without stamping the attempt before making it, a wrong password would
        // retry on every single run.
        $second = $this->pull();
        $this->assertStringContainsString('Refusing to log in', (string) $second['error']);
        Http::assertSentCount(1);
    }

    public function test_an_expired_token_triggers_one_fresh_login(): void
    {
        $this->connection->forceFill([
            'pull_token' => 'stale-token',
            'pull_token_expires_at' => now()->subDay(),
        ])->save();

        $this->fakeVendor();
        $summary = $this->pull();

        $this->assertTrue($summary['ok']);
        $this->assertEquals('jwt-from-vendor', $this->connection->fresh()->pull_token);
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $raw = DB::table('company_hrms_connections')->where('company_id', $this->company->id)->first();

        foreach (['pull_base_url', 'pull_email', 'pull_password'] as $column) {
            $this->assertNotEmpty($raw->{$column});
            $this->assertStringNotContainsString('vendor-secret', $raw->{$column});
            $this->assertStringNotContainsString('hrms.cyberpulse.test', $raw->{$column});
        }

        $this->fakeVendor();
        $this->pull();

        $raw = DB::table('company_hrms_connections')->where('company_id', $this->company->id)->first();
        $this->assertStringNotContainsString('jwt-from-vendor', (string) $raw->pull_token);
    }

    // ------------------------------------------------------------------ dry run

    public function test_a_dry_run_reports_what_would_happen_and_writes_nothing(): void
    {
        $this->fakeVendor();
        $summary = $this->pull(dryRun: true);

        $this->assertTrue($summary['dry_run']);
        $this->assertEquals(2, $summary['applied']);
        $this->assertEquals(1, $summary['ignored']);
        $this->assertEquals(1, $summary['unknown_employee']);

        $this->assertDatabaseCount('skips', 0);
        $this->assertDatabaseCount('hrms_webhook_events', 0);
        // Not even the external_id backfill, which is why that write lives in the
        // apply step rather than the mapper.
        $this->assertNull($this->bob->fresh()->external_id);
        $this->assertNull($this->connection->fresh()->last_pull_at);
    }

    public function test_a_dry_run_reports_cancellations_without_making_them(): void
    {
        $this->fakeVendor();
        $this->pull();

        $remaining = array_values(array_filter(
            $this->fixture()['data'],
            fn ($row) => $row['_id'] !== '66f1aa0000000000000000a1',
        ));

        $this->fakeVendor($remaining);
        $summary = $this->pull(dryRun: true);

        $this->assertEquals(1, $summary['cancelled']);
        $this->assertEquals(2, Skip::where('employee_id', $this->alice->id)->whereNull('cancelled_at')->count());
    }

    // -------------------------------------------------------------- run state

    public function test_the_run_records_its_outcome_for_the_health_page(): void
    {
        $this->fakeVendor();
        $this->pull();

        $connection = $this->connection->fresh();

        $this->assertNotNull($connection->last_pull_at);
        $this->assertEquals('ok', $connection->last_pull_status);
        $this->assertEquals(2, $connection->last_pull_summary['applied']);
        $this->assertNull($connection->last_pull_error);
        // Per-leave detail is deliberately not persisted - it can run to
        // hundreds of rows and the counts are what the page shows.
        $this->assertArrayNotHasKey('details', $connection->last_pull_summary);
    }

    public function test_a_failed_run_records_the_error(): void
    {
        $this->fakeVendor(fetchStatus: 503);
        $this->pull();

        $connection = $this->connection->fresh();

        $this->assertEquals('failed', $connection->last_pull_status);
        $this->assertStringContainsString('503', (string) $connection->last_pull_error);
    }

    public function test_a_company_with_no_credentials_is_reported_not_crashed(): void
    {
        $this->connection->forceFill(['pull_base_url' => null, 'pull_email' => null, 'pull_password' => null])->save();

        $summary = $this->pull();

        $this->assertFalse($summary['ok']);
        $this->assertStringContainsString('No CyberPulse credentials', (string) $summary['error']);
        Http::assertNothingSent();
    }

    /**
     * The dry run exists to be pointed at a live HR system before a real one, so
     * it has to agree with the real run. A leave falling entirely on a holiday
     * is actionable in principle and has nothing to act on, which the apply step
     * records as ignored.
     */
    public function test_a_leave_landing_only_on_a_holiday_is_reported_as_ignored_by_both_runs(): void
    {
        CompanyCalendarDay::create([
            'company_id' => $this->company->id,
            'date' => '2026-10-08',
            'type' => 'holiday',
            'note' => 'Founders Day',
        ]);

        $rows = $this->onlyLeaves('66f1aa0000000000000000a1');
        $rows[0]['startDate'] = '2026-10-08T00:00:00.000Z';
        $rows[0]['endDate'] = '2026-10-08T00:00:00.000Z';
        $this->fakeVendor($rows);

        $dry = $this->pull(dryRun: true);
        $this->assertEquals(0, $dry['applied']);
        $this->assertEquals(1, $dry['ignored']);

        $real = $this->pull();
        $this->assertEquals(0, $real['applied']);
        $this->assertEquals(1, $real['ignored']);

        // And the event row agrees too.
        $this->assertEquals(
            HrmsWebhookEvent::STATUS_IGNORED,
            HrmsWebhookEvent::where('leave_external_id', 'cp:leave:66f1aa0000000000000000a1')->sole()->status,
        );
        $this->assertDatabaseCount('skips', 0);
    }

    // --------------------------------------------------- re-approval revisions

    /**
     * The whole point of the revision suffix.
     *
     * Approved, withdrawn, approved again: without it the second approval reuses
     * the id the first one consumed, dedupes, applies nothing - and the person's
     * meal is counted while they are away.
     */
    public function test_a_leave_approved_again_after_a_cancellation_is_applied_again(): void
    {
        $leave = $this->onlyLeaves('66f1aa0000000000000000a1');
        $ref = 'cp:leave:66f1aa0000000000000000a1';

        // 1. Approved.
        $this->fakeVendor($leave);
        $this->assertEquals(1, $this->pull()['applied']);
        $this->assertEquals(2, Skip::whereNull('cancelled_at')->count());

        // 2. Rejected in the HR system.
        $rejected = $leave;
        $rejected[0]['status'] = 'Rejected';
        $this->fakeVendor($rejected);
        $this->assertEquals(1, $this->pull()['cancelled']);
        $this->assertEquals(0, Skip::whereNull('cancelled_at')->count());

        // 3. Approved again. This is what used to be swallowed.
        $this->fakeVendor($leave);
        $third = $this->pull();

        $this->assertEquals(1, $third['applied']);
        $this->assertEquals(2, Skip::whereNull('cancelled_at')->count());

        $this->assertEquals(
            [$ref.':approved', $ref.':cancelled', $ref.':approved:r1'],
            HrmsWebhookEvent::orderBy('id')->pluck('external_event_id')->all(),
        );
    }

    /**
     * Revision 0 keeps the documented form, so the ordinary case does not change
     * shape just because the mechanism exists.
     */
    public function test_a_first_approval_keeps_the_plain_documented_id(): void
    {
        $this->fakeVendor($this->onlyLeaves('66f1aa0000000000000000a1'));
        $this->pull();

        $this->assertEquals(
            'cp:leave:66f1aa0000000000000000a1:approved',
            HrmsWebhookEvent::sole()->external_event_id,
        );
    }

    public function test_the_cycle_can_repeat_more_than_once(): void
    {
        $leave = $this->onlyLeaves('66f1aa0000000000000000a1');
        $rejected = $leave;
        $rejected[0]['status'] = 'Rejected';
        $ref = 'cp:leave:66f1aa0000000000000000a1';

        foreach ([1, 2, 3] as $round) {
            $this->fakeVendor($leave);
            $this->assertEquals(1, $this->pull()['applied'], "round {$round} approval");

            $this->fakeVendor($rejected);
            $this->assertEquals(1, $this->pull()['cancelled'], "round {$round} cancellation");
        }

        $this->assertEquals([
            $ref.':approved', $ref.':cancelled',
            $ref.':approved:r1', $ref.':cancelled:r1',
            $ref.':approved:r2', $ref.':cancelled:r2',
        ], HrmsWebhookEvent::orderBy('id')->pluck('external_event_id')->all());

        $this->assertEquals(0, Skip::whereNull('cancelled_at')->count());
    }

    /**
     * The suffix must not cost idempotency: a repeat run inside the same state
     * has to produce the same id and dedupe.
     */
    public function test_a_repeat_run_is_still_idempotent_after_a_revision(): void
    {
        $leave = $this->onlyLeaves('66f1aa0000000000000000a1');
        $rejected = $leave;
        $rejected[0]['status'] = 'Rejected';

        $this->fakeVendor($leave);
        $this->pull();
        $this->fakeVendor($rejected);
        $this->pull();
        $this->fakeVendor($leave);
        $this->pull();

        $events = HrmsWebhookEvent::count();
        $skips = Skip::orderBy('id')->get()->map(fn ($s) => [$s->date, $s->cancelled_at])->all();

        // Three more runs in the same state change nothing.
        foreach ([1, 2, 3] as $ignored) {
            $summary = $this->pull();
            $this->assertEquals(0, $summary['applied']);
            $this->assertEquals(0, $summary['cancelled']);
            $this->assertGreaterThan(0, $summary['duplicate']);
        }

        $this->assertEquals($events, HrmsWebhookEvent::count());
        $this->assertEquals($skips, Skip::orderBy('id')->get()->map(fn ($s) => [$s->date, $s->cancelled_at])->all());
    }

    /**
     * The limit of the restore rule, asserted on RecordSkip directly.
     *
     * A company admin cancelling an HRMS skip is overriding the HR system on
     * purpose, so the same leave presenting itself again must be refused. Going
     * through a pull would not prove this - there the protection comes from the
     * event id deduping, not from this rule.
     */
    public function test_an_automated_source_cannot_restore_a_skip_a_person_cancelled(): void
    {
        $admin = User::where('company_id', $this->company->id)->where('role', 'company_admin')->sole();

        $skip = Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->alice->id,
            'date' => '2026-10-08', 'source' => 'leave', 'external_ref' => 'cp:leave:x1',
        ]);

        // A person cancels it, so cancelled_source stays null.
        app(CancelSkip::class)->execute($this->company, $skip, $admin);
        $this->assertNull($skip->fresh()->cancelled_source);

        // The very same leave reference comes back from the HR system.
        $result = app(RecordSkip::class)->execute(
            $this->company, $this->alice, '2026-10-08', 'leave', null, null, 'cp:leave:x1',
        );

        $this->assertEquals(SkipOutcome::BLOCKED_CANCELLED, $result->outcome);
        $this->assertNotNull($skip->fresh()->cancelled_at);
    }

    /**
     * The other half: the integration may restore its own release.
     */
    public function test_an_automated_source_can_restore_its_own_release(): void
    {
        $admin = User::where('company_id', $this->company->id)->where('role', 'company_admin')->sole();

        $skip = Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->alice->id,
            'date' => '2026-10-08', 'source' => 'leave', 'external_ref' => 'cp:leave:x1',
        ]);

        app(CancelSkip::class)->execute($this->company, $skip, $admin, 'hrms');

        $result = app(RecordSkip::class)->execute(
            $this->company, $this->alice, '2026-10-08', 'leave', null, null, 'cp:leave:x1',
        );

        $this->assertEquals(SkipOutcome::REACTIVATED, $result->outcome);
        $this->assertNull($skip->fresh()->cancelled_at);
        $this->assertNull($skip->fresh()->cancelled_source);
    }

    /**
     * And a different leave landing on a day the integration had freed is still
     * refused: the reference has to match, not merely be automated.
     */
    public function test_a_different_leave_cannot_take_over_a_released_day(): void
    {
        $admin = User::where('company_id', $this->company->id)->where('role', 'company_admin')->sole();

        $skip = Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->alice->id,
            'date' => '2026-10-08', 'source' => 'leave', 'external_ref' => 'cp:leave:x1',
        ]);

        app(CancelSkip::class)->execute($this->company, $skip, $admin, 'hrms');

        $result = app(RecordSkip::class)->execute(
            $this->company, $this->alice, '2026-10-08', 'leave', null, null, 'cp:leave:SOMETHING-ELSE',
        );

        $this->assertEquals(SkipOutcome::BLOCKED_CANCELLED, $result->outcome);
    }

    /**
     * Even when the leave is withdrawn and re-approved around it: the skip the
     * admin cancelled is theirs, and the revision mechanism must not be a way in.
     */
    public function test_an_admin_cancellation_survives_a_full_withdraw_and_reapprove_cycle(): void
    {
        $leave = $this->onlyLeaves('66f1aa0000000000000000a1');
        $rejected = $leave;
        $rejected[0]['status'] = 'Rejected';

        $this->fakeVendor($leave);
        $this->pull();

        $admin = User::where('company_id', $this->company->id)->where('role', 'company_admin')->sole();
        $theirs = Skip::where('external_ref', 'cp:leave:66f1aa0000000000000000a1')->orderBy('date')->first();
        app(CancelSkip::class)->execute($this->company, $theirs, $admin);

        // Withdrawn, then approved again.
        $this->fakeVendor($rejected);
        $this->pull();
        $this->fakeVendor($leave);
        $this->pull();

        $this->assertNotNull($theirs->fresh()->cancelled_at);
        $this->assertNull($theirs->fresh()->cancelled_source, 'still recorded as a person cancellation');
    }

    public function test_an_integration_release_is_marked_as_such(): void
    {
        $leave = $this->onlyLeaves('66f1aa0000000000000000a1');
        $rejected = $leave;
        $rejected[0]['status'] = 'Rejected';

        $this->fakeVendor($leave);
        $this->pull();
        $this->fakeVendor($rejected);
        $this->pull();

        foreach (Skip::where('external_ref', 'cp:leave:66f1aa0000000000000000a1')->get() as $skip) {
            $this->assertEquals('hrms', $skip->cancelled_source);
        }
    }

    // ----------------------------------------------------------- health page

    public function test_the_health_page_reports_each_pull(): void
    {
        $this->fakeVendor();
        $this->pull();

        $root = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test',
            'password' => bcrypt('password'), 'role' => 'super_admin',
        ]);

        $props = $this->actingAs($root)->get('/super-admin/health')
            ->getOriginalContent()->getData()['page']['props'];

        $row = collect($props['hrms_pulls'])->firstWhere('company_id', $this->company->id);

        $this->assertNotNull($row);
        $this->assertEquals('ALPHA1', $row['company_code']);
        $this->assertEquals('cyberpulse', $row['adapter']);
        $this->assertEquals('ok', $row['status']);
        $this->assertEquals(2, $row['applied']);
        $this->assertEquals(1, $row['unknown_employee']);
        $this->assertFalse($row['is_stale']);
    }

    /**
     * A pull that silently stopped looks exactly like a quiet day of no leave,
     * which is the one failure mode nobody would notice on their own.
     */
    public function test_a_pull_that_has_not_run_recently_is_flagged_stale(): void
    {
        $this->connection->forceFill([
            'last_pull_at' => now()->subHours(6),
            'last_pull_status' => 'ok',
            'last_pull_summary' => ['applied' => 1],
        ])->save();

        $root = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test',
            'password' => bcrypt('password'), 'role' => 'super_admin',
        ]);

        $props = $this->actingAs($root)->get('/super-admin/health')
            ->getOriginalContent()->getData()['page']['props'];

        $this->assertTrue(collect($props['hrms_pulls'])->firstWhere('company_id', $this->company->id)['is_stale']);
    }

    public function test_the_health_page_never_exposes_the_credentials(): void
    {
        $this->fakeVendor();
        $this->pull();

        $root = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test',
            'password' => bcrypt('password'), 'role' => 'super_admin',
        ]);

        $json = json_encode($this->actingAs($root)->get('/super-admin/health')
            ->getOriginalContent()->getData()['page']['props']);

        foreach (['vendor-secret', 'integration@alpha.test', 'jwt-from-vendor', self::BASE] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_the_page_reads_the_prop_the_controller_sends(): void
    {
        $health = file_get_contents(resource_path('js/Pages/SuperAdmin/Health.vue'));

        $this->assertStringContainsString('hrms_pulls', $health);
        $this->assertStringContainsString('stalePulls', $health);
    }

    // -------------------------------------------------------------- the command

    public function test_the_command_runs_a_dry_run_without_writing(): void
    {
        $this->fakeVendor();

        $this->artisan('hrms:pull', ['company' => 'ALPHA1', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('skips', 0);
    }

    public function test_the_command_applies_a_real_run(): void
    {
        $this->fakeVendor();

        $this->artisan('hrms:pull', ['company' => 'ALPHA1'])->assertSuccessful();

        $this->assertGreaterThan(0, Skip::count());
    }

    public function test_the_all_flag_pulls_every_configured_company(): void
    {
        $this->fakeVendor();

        $this->artisan('hrms:pull', ['--all' => true])
            ->expectsOutputToContain('ALPHA1: applied 2')
            ->assertSuccessful();

        $this->assertGreaterThan(0, Skip::count());
    }

    public function test_the_all_flag_reports_a_failure_without_stopping(): void
    {
        $this->fakeVendor(fetchStatus: 500);

        $this->artisan('hrms:pull', ['--all' => true])->assertFailed();
    }

    /**
     * The regular cadence can leave a gap right before the count locks, which is
     * when leave approved that morning matters most.
     */
    public function test_the_pre_cutoff_run_does_nothing_outside_the_window(): void
    {
        // Frozen at 08:00 IST; the cutoff is 11:00 and the lead is 20 minutes.
        $this->fakeVendor();

        $this->artisan('hrms:pull', ['--all' => true, '--before-cutoff' => true])
            ->expectsOutputToContain('Nothing due.')
            ->assertSuccessful();

        $this->assertDatabaseCount('skips', 0);
        Http::assertNothingSent();
    }

    public function test_the_pre_cutoff_run_fires_inside_the_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:45:00', 'Asia/Kolkata'));
        $this->fakeVendor();

        $this->artisan('hrms:pull', ['--all' => true, '--before-cutoff' => true])->assertSuccessful();

        $this->assertGreaterThan(0, Skip::count());
    }

    /**
     * It runs every minute, so it has to act at most once per window - otherwise
     * it would re-pull sixty times before each cutoff.
     */
    public function test_the_pre_cutoff_run_acts_only_once_per_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:45:00', 'Asia/Kolkata'));
        $this->fakeVendor();

        $this->artisan('hrms:pull', ['--all' => true, '--before-cutoff' => true])->assertSuccessful();
        $sentAfterFirst = 2; // one login, one fetch

        Carbon::setTestNow(Carbon::parse('2026-10-05 10:50:00', 'Asia/Kolkata'));

        $this->artisan('hrms:pull', ['--all' => true, '--before-cutoff' => true])
            ->expectsOutputToContain('Nothing due.')
            ->assertSuccessful();

        Http::assertSentCount($sentAfterFirst);
    }

    public function test_the_pre_cutoff_run_fires_again_the_next_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:45:00', 'Asia/Kolkata'));
        $this->fakeVendor();
        $this->artisan('hrms:pull', ['--all' => true, '--before-cutoff' => true])->assertSuccessful();

        // Tuesday, same window: yesterday's run must not satisfy today's.
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:45:00', 'Asia/Kolkata'));

        $this->artisan('hrms:pull', ['--all' => true, '--before-cutoff' => true])
            ->doesntExpectOutputToContain('Nothing due.')
            ->assertSuccessful();
    }

    public function test_the_command_fails_on_an_unknown_company(): void
    {
        $this->artisan('hrms:pull', ['company' => 'NOPE1'])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_the_command_resolves_the_only_configured_company(): void
    {
        $this->fakeVendor();

        $this->artisan('hrms:pull')->assertSuccessful();

        $this->assertGreaterThan(0, Skip::count());
    }
}
