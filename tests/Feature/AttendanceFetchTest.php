<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanySetting;
use App\Services\Hrms\Adapters\CyberPulseAdapter;
use App\Services\Hrms\CyberPulse\CyberPulseClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reading one day of attendance, and deciding one thing from it.
 *
 * Two responsibilities, kept apart. The client is the privacy boundary: an
 * attendance record is the most invasive thing the HR system holds - selfie
 * photographs, GPS coordinates, an emergency reason - and none of it may
 * survive the parse. The adapter is the judgement: had this person arrived by
 * the time we had to order.
 */
class AttendanceFetchTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected CompanyHrmsConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->connection = CompanyHrmsConnection::create([
            'company_id' => $this->company->id,
            'pull_adapter' => 'cyberpulse',
            'pull_base_url' => 'https://hrms.example.test',
            'attendance_api_key' => 'secret-attendance-key',
        ]);
    }

    /** @param array<string, mixed> $body */
    protected function fakeVendor(array $body, int $status = 200): void
    {
        Http::fake([
            '*/api/integration/attendance/daily*' => Http::response($body, $status),
        ]);
    }

    // --------------------------------------------------------- the credentials

    public function test_no_key_means_no_attendance_to_pull(): void
    {
        $this->connection->forceFill(['attendance_api_key' => null])->save();

        $result = app(CyberPulseClient::class)->fetchAttendance($this->connection->fresh(), '2026-10-09');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('No attendance API key', (string) $result->error);
    }

    /**
     * Keyed, not a login. MealBells must not hold a person's password in order
     * to read whether their colleagues turned up.
     */
    public function test_it_sends_the_key_as_a_header_and_never_logs_in(): void
    {
        $this->fakeVendor(['date' => '2026-10-09', 'employees' => []]);

        app(CyberPulseClient::class)->fetchAttendance($this->connection, '2026-10-09');

        Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'secret-attendance-key'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/employee/login'));
    }

    /**
     * The vendor builds its day boundary from the server's local time, so an
     * Indian office on a UTC host would otherwise be asking about the wrong day
     * for its first five and a half hours.
     */
    public function test_it_asks_about_a_date_in_the_companys_timezone(): void
    {
        $this->fakeVendor(['date' => '2026-10-09', 'employees' => []]);

        app(CyberPulseClient::class)->fetchAttendance($this->connection, '2026-10-09');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'date=2026-10-09')
            && str_contains(urldecode($request->url()), 'tz=Asia/Kolkata'));
    }

    /**
     * An answer about a different day is not an answer to our question.
     */
    public function test_an_answer_for_the_wrong_date_is_a_failure(): void
    {
        $this->fakeVendor(['date' => '2026-10-08', 'employees' => [['employee_id' => 'x', 'clocked_in' => true]]]);

        $result = app(CyberPulseClient::class)->fetchAttendance($this->connection, '2026-10-09');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('answered for 2026-10-08', (string) $result->error);
    }

    public function test_an_empty_day_is_a_success_not_a_failure(): void
    {
        $this->fakeVendor(['date' => '2026-10-09', 'employees' => []]);

        $result = app(CyberPulseClient::class)->fetchAttendance($this->connection, '2026-10-09');

        $this->assertTrue($result->ok, 'nobody clocking in was treated as an error');
        $this->assertSame([], $result->leaves);
    }

    /** @return array<string, array<int, int>> */
    public static function badStatuses(): array
    {
        return ['unauthorised' => [401], 'not found' => [404], 'server error' => [500]];
    }

    #[DataProvider('badStatuses')]
    public function test_an_unsuccessful_response_is_a_failure(int $status): void
    {
        $this->fakeVendor(['message' => 'no'], $status);

        $this->assertFalse(app(CyberPulseClient::class)->fetchAttendance($this->connection, '2026-10-09')->ok);
    }

    public function test_a_body_without_an_employees_array_is_a_failure(): void
    {
        $this->fakeVendor(['date' => '2026-10-09', 'attendance' => []]);

        $this->assertFalse(app(CyberPulseClient::class)->fetchAttendance($this->connection, '2026-10-09')->ok);
    }

    public function test_a_transport_failure_is_a_failure_and_leaks_no_key(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out contacting secret-attendance-key'));

        $result = app(CyberPulseClient::class)->fetchAttendance($this->connection, '2026-10-09');

        $this->assertFalse($result->ok);
        $this->assertStringNotContainsString('secret-attendance-key', (string) $result->error);
    }

    // ------------------------------------------------------ the privacy boundary

    /**
     * The reason this class exists. Everything the HR system holds on an
     * attendance record beyond the five fields is discarded at the parse, before
     * it can reach a log line, an exception message or the database.
     */
    public function test_nothing_outside_the_whitelist_survives_the_fetch(): void
    {
        $this->fakeVendor(['date' => '2026-10-09', 'employees' => [[
            'employee_id' => '66e0bb01',
            'email' => 'alice@acme.test',
            'clocked_in' => true,
            'clock_in_at' => '2026-10-09T04:15:00Z',
            'is_wfh' => false,
            // Everything below must not survive.
            'clockInSelfie' => '/uploads/selfies/alice-2026-10-09.jpg',
            'clockOutSelfie' => '/uploads/selfies/alice-out.jpg',
            'clockInLocation' => ['latitude' => 18.5204, 'longitude' => 73.8567, 'address' => '12 Example Road, Pune'],
            'isEmergency' => true,
            'emergencyReason' => 'Hospital visit',
            'breakTimings' => [['name' => 'lunch', 'startTime' => '13:00']],
            'salary' => 1450000,
            'department' => 'Engineering',
            'position' => 'Developer',
            'image' => 'alice.jpg',
        ]]]);

        $result = app(CyberPulseClient::class)->fetchAttendance($this->connection, '2026-10-09');

        $this->assertTrue($result->ok);
        $this->assertSame(
            ['employee_id', 'email', 'clocked_in', 'clock_in_at', 'is_wfh'],
            array_keys($result->leaves[0]),
        );

        $json = json_encode($result->leaves);

        foreach ([
            'selfie', 'latitude', 'longitude', 'Example Road', 'Hospital visit',
            'breakTimings', '1450000', 'Engineering', 'Developer', 'alice.jpg',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "'{$forbidden}' survived the fetch");
        }
    }

    // ------------------------------------------------------------ the judgement

    /** @return array<string, array<int, mixed>> */
    public static function clockIns(): array
    {
        // Cutoff is 11:00 Asia/Kolkata, which is 05:30 UTC.
        return [
            'well before cutoff' => ['2026-10-09T04:15:00Z', true],
            'a minute before' => ['2026-10-09T05:29:00Z', true],
            'exactly on the cutoff minute' => ['2026-10-09T05:30:00Z', true],
            'a minute after' => ['2026-10-09T05:31:00Z', false],
            'well after' => ['2026-10-09T05:50:00Z', false],
        ];
    }

    #[DataProvider('clockIns')]
    public function test_it_decides_whether_they_arrived_before_the_cutoff(string $clockInAt, bool $expected): void
    {
        $decision = (new CyberPulseAdapter)->toAttendance(
            ['employee_id' => 'x', 'clocked_in' => true, 'clock_in_at' => $clockInAt],
            '2026-10-09',
            'Asia/Kolkata',
            '11:00:00',
        );

        $this->assertSame($expected, $decision['clocked_in_by_cutoff']);
    }

    public function test_no_clock_in_is_a_known_absence(): void
    {
        $decision = (new CyberPulseAdapter)->toAttendance(
            ['employee_id' => 'x', 'clocked_in' => false, 'clock_in_at' => null],
            '2026-10-09', 'Asia/Kolkata', '11:00:00',
        );

        $this->assertFalse($decision['clocked_in_by_cutoff']);
    }

    /**
     * The fail-safe, and the one that matters most. clockInTime is encrypted at
     * rest in the HR system and its decrypt hook does not run for .lean() or
     * aggregation queries, so ciphertext reaching us is a real possibility -
     * and nobody should lose a meal to a vendor bug.
     */
    public function test_an_unreadable_clock_in_is_unknown_rather_than_absent(): void
    {
        foreach (['enc:3f2a:9c8d7e', 'not a date', ''] as $unreadable) {
            $decision = (new CyberPulseAdapter)->toAttendance(
                ['employee_id' => 'x', 'clocked_in' => true, 'clock_in_at' => $unreadable],
                '2026-10-09', 'Asia/Kolkata', '11:00:00',
            );

            $this->assertNull(
                $decision['clocked_in_by_cutoff'],
                "'{$unreadable}' was judged rather than treated as unknown",
            );
        }
    }

    public function test_a_row_with_no_employee_reference_is_refused(): void
    {
        $this->assertNull((new CyberPulseAdapter)->toAttendance(
            ['clocked_in' => true, 'clock_in_at' => '2026-10-09T04:15:00Z'],
            '2026-10-09', 'Asia/Kolkata', '11:00:00',
        ));
    }

    public function test_an_email_alone_is_enough_to_identify_a_row(): void
    {
        $decision = (new CyberPulseAdapter)->toAttendance(
            ['email' => 'alice@acme.test', 'clocked_in' => false],
            '2026-10-09', 'Asia/Kolkata', '11:00:00',
        );

        $this->assertNotNull($decision);
        $this->assertSame('alice@acme.test', $decision['email']);
    }

    /**
     * Work-from-home people do clock in, and only the attendance record knows
     * it was from home - so the flag has to survive, or they would be counted
     * as present in the office.
     */
    public function test_the_work_from_home_flag_survives(): void
    {
        $decision = (new CyberPulseAdapter)->toAttendance(
            ['employee_id' => 'x', 'clocked_in' => true, 'clock_in_at' => '2026-10-09T04:00:00Z', 'is_wfh' => true],
            '2026-10-09', 'Asia/Kolkata', '11:00:00',
        );

        $this->assertTrue($decision['is_wfh']);
        $this->assertTrue($decision['clocked_in_by_cutoff']);
    }

    /**
     * The decision is kept and the measurement is thrown away, so no caller can
     * accidentally persist an arrival time.
     */
    public function test_the_decision_carries_no_arrival_time(): void
    {
        $decision = (new CyberPulseAdapter)->toAttendance(
            ['employee_id' => 'x', 'clocked_in' => true, 'clock_in_at' => '2026-10-09T04:15:00Z'],
            '2026-10-09', 'Asia/Kolkata', '11:00:00',
        );

        $this->assertSame(
            ['reference', 'email', 'clocked_in_by_cutoff', 'is_wfh'],
            array_keys($decision),
        );
        $this->assertStringNotContainsString('04:15', json_encode($decision));
    }

    /**
     * A company on a different clock gets a different answer from the same
     * instant, which is the point of passing the timezone at all.
     */
    public function test_the_same_instant_is_judged_against_the_companys_own_clock(): void
    {
        $row = ['employee_id' => 'x', 'clocked_in' => true, 'clock_in_at' => '2026-10-09T05:45:00Z'];

        $india = (new CyberPulseAdapter)->toAttendance($row, '2026-10-09', 'Asia/Kolkata', '11:00:00');
        $london = (new CyberPulseAdapter)->toAttendance($row, '2026-10-09', 'Europe/London', '11:00:00');

        // 05:45 UTC is 11:15 in Kolkata - late - and 06:45 in London - early.
        $this->assertFalse($india['clocked_in_by_cutoff']);
        $this->assertTrue($london['clocked_in_by_cutoff']);
    }
}
