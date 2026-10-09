<?php

namespace App\Services\Hrms\CyberPulse;

use App\Models\CompanyHrmsConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to CyberPulse HRMS. Read-only apart from the login it needs to read.
 *
 * Two things this class is responsible for beyond the HTTP:
 *
 * 1. Token handling. CyberPulse issues a 30-day JWT with no refresh, so we cache
 *    it encrypted and log in only when we hold none or the vendor rejects the one
 *    we hold. A self-imposed cap of one login per minute per company means a
 *    crash loop cannot hammer their login endpoint - they have no lockout of
 *    their own, which makes restraint our job.
 *
 * 2. The privacy boundary. /api/leave/fetchAll returns whole employee records,
 *    including payroll and identity fields MealBells has no business holding. The
 *    whitelist below is applied the moment the body is parsed, before anything is
 *    logged, returned or persisted. Nothing outside it leaves this class.
 */
class CyberPulseClient
{
    /**
     * The only fields that survive a fetch. Everything else - bank details,
     * salary, date of birth, address, documents, images - is discarded here.
     */
    public const LEAVE_FIELDS = ['_id', 'startDate', 'endDate', 'leaveType', 'status'];

    public const EMPLOYEE_FIELDS = ['_id', 'email', 'name'];

    /**
     * The only fields that survive an attendance fetch.
     *
     * Stricter than the leave whitelist, because an attendance record is the
     * most invasive thing the HR system holds: selfie photographs at clock-in
     * and clock-out, GPS latitude and longitude with a resolved address, an
     * emergency reason in free text, and every break taken. None of it helps
     * count meals, and holding it would make us responsible for data we have no
     * reason to have.
     *
     * clock_in_at is read and then thrown away - it is used once, to decide
     * whether the person had arrived by the cutoff, and never stored.
     */
    public const ATTENDANCE_FIELDS = ['employee_id', 'email', 'clocked_in', 'clock_in_at', 'is_wfh'];

    public function fetchLeaves(CompanyHrmsConnection $connection): CyberPulseResult
    {
        if (! $connection->hasPullCredentials()) {
            return CyberPulseResult::failure('No CyberPulse credentials are configured for this company.');
        }

        $loggedIn = false;

        if (! $connection->hasLiveToken()) {
            $login = $this->login($connection);

            if (! $login->ok) {
                return $login;
            }

            $loggedIn = true;
        }

        $response = $this->get($connection, '/api/leave/fetchAll');

        // The token is a month old at most but can still be refused - revoked,
        // or the vendor restarted with a new signing key. 403 counts as well as
        // 401: vendors are inconsistent about which they use for a token they
        // will not accept.
        //
        // The token is discarded before the retry, so that even if the login is
        // then refused by the once-a-minute cap, the next run starts clean
        // instead of presenting the same dead token again.
        if ($response !== null && in_array($response->status(), [401, 403], true) && ! $loggedIn) {
            $connection->forceFill(['pull_token' => null, 'pull_token_expires_at' => null])->save();

            $login = $this->login($connection);

            if (! $login->ok) {
                return $login;
            }

            // One retry after a fresh login, never a loop.
            $loggedIn = true;
            $response = $this->get($connection, '/api/leave/fetchAll');
        }

        if ($response === null) {
            return CyberPulseResult::failure('CyberPulse did not respond to the leave fetch.');
        }

        if (! $response->successful()) {
            return CyberPulseResult::failure(
                "CyberPulse returned {$response->status()} for the leave fetch.",
                $response->status(),
            );
        }

        $body = $response->json();

        if (! is_array($body) || ($body['success'] ?? null) === false) {
            return CyberPulseResult::failure('CyberPulse reported the leave fetch as unsuccessful.');
        }

        $rows = $body['data'] ?? null;

        if (! is_array($rows)) {
            return CyberPulseResult::failure('CyberPulse leave fetch carried no data array.');
        }

        return CyberPulseResult::success($this->whitelist($rows), $loggedIn);
    }

    /**
     * One day of attendance: who had clocked in, for every active employee.
     *
     * A different endpoint from the leave fetch, and a different kind of
     * authentication. It is keyed rather than token-based, so this performs no
     * login at all: we are not holding a real person's credentials to read
     * whether their colleagues turned up, and a service account appearing in
     * the vendor's own attendance reports as an employee would confuse
     * everyone.
     *
     * The absence of a key is a configuration state, not an error - a company
     * that has not been given one simply has no attendance to pull.
     */
    public function fetchAttendance(CompanyHrmsConnection $connection, string $date): CyberPulseResult
    {
        if (! $connection->hasAttendanceCredentials()) {
            return CyberPulseResult::failure('No attendance API key is configured for this company.');
        }

        $timezone = $connection->company?->setting?->timezone
            ?? config('mealbells.default_timezone', 'Asia/Kolkata');

        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-API-Key' => (string) $connection->attendance_api_key])
                ->timeout((int) config('hrms.cyberpulse.timeout_seconds', 15))
                ->get($this->url($connection, '/api/integration/attendance/daily'), [
                    'date' => $date,
                    // Sent as well as expected back: the vendor's own day
                    // boundary is built from the server's local time, so an
                    // Indian office on a UTC host would otherwise be asking
                    // about the wrong day for its first five and a half hours.
                    'tz' => $timezone,
                ]);
        } catch (\Throwable $e) {
            // Our message, not theirs: a transport exception can carry the
            // request, and the request carries the key.
            Log::warning("CyberPulse attendance fetch failed for company {$connection->company_id}: transport error.");

            return CyberPulseResult::failure('Could not reach CyberPulse for the attendance fetch.');
        }

        if (! $response->successful()) {
            return CyberPulseResult::failure(
                "CyberPulse returned {$response->status()} for the attendance fetch.",
                $response->status(),
            );
        }

        $body = $response->json();

        if (! is_array($body) || ! is_array($body['employees'] ?? null)) {
            return CyberPulseResult::failure('CyberPulse attendance fetch carried no employees array.');
        }

        // A day the vendor answered for, with a timezone that is not the one we
        // asked about, is not an answer to our question.
        $answeredFor = (string) ($body['date'] ?? '');

        if ($answeredFor !== '' && $answeredFor !== $date) {
            return CyberPulseResult::failure(
                "CyberPulse answered for {$answeredFor} when asked about {$date}."
            );
        }

        return CyberPulseResult::success($this->whitelistAttendance($body['employees']));
    }

    /**
     * Reduce the vendor's attendance rows to the five fields we may hold.
     *
     * Applied the moment the body is parsed, before anything is logged,
     * returned or persisted - so a selfie path or a coordinate cannot reach a
     * log line, an exception message or the database even once.
     *
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function whitelistAttendance(array $rows): array
    {
        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $kept = [];

            foreach (self::ATTENDANCE_FIELDS as $field) {
                if (array_key_exists($field, $row)) {
                    $kept[$field] = is_scalar($row[$field]) ? $row[$field] : null;
                }
            }

            $clean[] = $kept;
        }

        return $clean;
    }

    /**
     * Reduce the vendor's rows to the fields we are allowed to hold.
     *
     * Applied before anything else touches the body, so a field we never asked
     * for cannot reach a log line, an exception message or the database.
     *
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function whitelist(array $rows): array
    {
        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $leave = [];

            foreach (self::LEAVE_FIELDS as $field) {
                if (array_key_exists($field, $row)) {
                    $leave[$field] = is_scalar($row[$field]) ? $row[$field] : null;
                }
            }

            $employee = is_array($row['employeeId'] ?? null) ? $row['employeeId'] : [];
            $leave['employeeId'] = [];

            foreach (self::EMPLOYEE_FIELDS as $field) {
                if (array_key_exists($field, $employee)) {
                    $leave['employeeId'][$field] = is_scalar($employee[$field]) ? $employee[$field] : null;
                }
            }

            $clean[] = $leave;
        }

        return $clean;
    }

    /**
     * POST /api/employee/login. The one write this integration performs against
     * the vendor, and only to obtain a read token.
     */
    protected function login(CompanyHrmsConnection $connection): CyberPulseResult
    {
        $cap = (int) config('hrms.cyberpulse.min_seconds_between_logins', 60);

        if ($connection->last_login_at && $connection->last_login_at->diffInSeconds(now()) < $cap) {
            return CyberPulseResult::failure(
                "Refusing to log in to CyberPulse again within {$cap}s of the last attempt."
            );
        }

        // Stamped before the attempt, so a failing login is rate limited too -
        // otherwise a wrong password would retry every run.
        $connection->forceFill(['last_login_at' => now()])->save();

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('hrms.cyberpulse.timeout_seconds', 15))
                ->post($this->url($connection, '/api/employee/login'), [
                    'email' => $connection->pull_email,
                    'password' => $connection->pull_password,
                ]);
        } catch (\Throwable $e) {
            // The message is ours, not the vendor's: a transport exception can
            // carry the request body, and the request body is a password.
            Log::warning("CyberPulse login failed for company {$connection->company_id}: transport error.");

            return CyberPulseResult::failure('Could not reach CyberPulse to sign in.');
        }

        if (! $response->successful()) {
            return CyberPulseResult::failure(
                "CyberPulse rejected the sign-in with {$response->status()}.",
                $response->status(),
            );
        }

        $token = $response->json('token');

        if (! is_string($token) || trim($token) === '') {
            return CyberPulseResult::failure('CyberPulse sign-in returned no token.');
        }

        $connection->forceFill([
            'pull_token' => $token,
            // The vendor says 30 days and offers no expiry in the response. Held
            // slightly short of that so a token is replaced before it lapses
            // mid-run rather than after.
            'pull_token_expires_at' => now()->addDays((int) config('hrms.cyberpulse.token_days', 29)),
        ])->save();

        return CyberPulseResult::success([], loggedIn: true);
    }

    protected function get(CompanyHrmsConnection $connection, string $path): ?Response
    {
        try {
            return Http::acceptJson()
                ->withToken((string) $connection->pull_token)
                ->timeout((int) config('hrms.cyberpulse.timeout_seconds', 15))
                ->get($this->url($connection, $path));
        } catch (\Throwable $e) {
            Log::warning("CyberPulse fetch failed for company {$connection->company_id}: {$e->getMessage()}");

            return null;
        }
    }

    protected function url(CompanyHrmsConnection $connection, string $path): string
    {
        return rtrim((string) $connection->pull_base_url, '/').$path;
    }
}
