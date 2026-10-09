<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\Skip;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The command, and the scheduler's path through it.
 *
 * The output matters as much as the behaviour: this exists to put a number in
 * front of a person deciding whether to act on attendance, and a report that
 * does not say "no count was changed" invites exactly the wrong conclusion.
 */
class AttendancePullCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            'attendance_absence_enabled' => true,
        ]);

        CompanyHrmsConnection::create([
            'company_id' => $this->company->id,
            'pull_adapter' => 'cyberpulse',
            'pull_base_url' => 'https://hrms.example.test',
            'attendance_api_key' => 'secret-attendance-key',
        ]);

        // Ten, not four: with four employees two absences are 50% and trip the
        // safety guard, so the report under test would never be printed.
        foreach (range(1, 10) as $i) {
            Employee::create([
                'company_id' => $this->company->id,
                'employee_code' => 'ACME'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => "Person {$i}", 'status' => 'active', 'is_meal_eligible' => true,
                'attendance_source' => 'integrated', 'external_id' => 'hr-'.$i,
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    protected function fakeVendor(array $rows): void
    {
        Http::fake([
            '*/api/integration/attendance/daily*' => Http::response([
                'date' => '2026-10-09', 'employees' => $rows,
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    protected function row(string $hrId, bool $clockedIn): array
    {
        return [
            'employee_id' => $hrId, 'email' => null,
            'clocked_in' => $clockedIn,
            'clock_in_at' => $clockedIn ? '2026-10-09T04:15:00Z' : null,
            'is_wfh' => false,
        ];
    }

    public function test_it_reports_the_would_be_absent_number(): void
    {
        $this->fakeVendor([
            $this->row('hr-1', false), $this->row('hr-2', false),
            $this->row('hr-3', true), $this->row('hr-4', true),
        ]);

        $this->artisan('hrms:pull-attendance', ['company' => 'ACME01', '--date' => '2026-10-09'])
            ->expectsOutputToContain('Shadow mode: this changes no meal count and creates no skip.')
            ->expectsOutputToContain('WOULD-BE ABSENT')
            ->expectsOutputToContain('No count was changed.')
            ->assertSuccessful();
    }

    /**
     * Someone reading a report that says "4 absent" without the rest would
     * reasonably conclude four meals were saved. They were not, and might not
     * be: two of them are already on leave.
     */
    public function test_it_separates_absence_from_meals_actually_saved(): void
    {
        $employees = Employee::where('company_id', $this->company->id)->orderBy('id')->get();

        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $employees[0]->id,
            'date' => '2026-10-09', 'source' => 'leave',
        ]);

        $this->fakeVendor([
            $this->row('hr-1', false), $this->row('hr-2', false),
            $this->row('hr-3', true), $this->row('hr-4', true),
        ]);

        $this->artisan('hrms:pull-attendance', ['company' => 'ACME01', '--date' => '2026-10-09'])
            ->expectsOutputToContain('Already not eating')
            ->assertSuccessful();
    }

    public function test_a_failed_fetch_fails_the_command_and_says_nothing_was_recorded(): void
    {
        Http::fake(['*/api/integration/attendance/daily*' => Http::response([], 500)]);

        $this->artisan('hrms:pull-attendance', ['company' => 'ACME01', '--date' => '2026-10-09'])
            ->expectsOutputToContain('Nothing was recorded')
            ->assertFailed();
    }

    public function test_an_unmatched_row_is_named_and_explained(): void
    {
        $this->fakeVendor([
            ['employee_id' => 'hr-stranger', 'email' => null, 'clocked_in' => false, 'clock_in_at' => null, 'is_wfh' => false],
            $this->row('hr-1', true),
        ]);

        $this->artisan('hrms:pull-attendance', ['company' => 'ACME01', '--date' => '2026-10-09'])
            ->expectsOutputToContain('hr-stranger')
            ->expectsOutputToContain('treated as present, so no meal is lost')
            ->assertSuccessful();
    }

    public function test_a_company_without_the_setting_says_so(): void
    {
        CompanySetting::where('company_id', $this->company->id)
            ->update(['attendance_absence_enabled' => false]);

        $this->artisan('hrms:pull-attendance', ['company' => 'ACME01'])
            ->expectsOutputToContain('not enabled')
            ->assertSuccessful();
    }

    public function test_an_unknown_company_code_fails(): void
    {
        $this->artisan('hrms:pull-attendance', ['company' => 'NOPE'])->assertFailed();
    }

    public function test_the_single_enabled_company_needs_no_code(): void
    {
        $this->fakeVendor([$this->row('hr-1', true)]);

        $this->artisan('hrms:pull-attendance', ['--date' => '2026-10-09'])->assertSuccessful();
    }

    // ----------------------------------------------------- the scheduler's path

    public function test_all_skips_a_company_outside_its_pre_cutoff_window(): void
    {
        // 08:00 IST, with a cutoff of 11:00 - nowhere near the window.
        Carbon::setTestNow(Carbon::parse('2026-10-09 08:00:00', 'Asia/Kolkata'));

        $this->artisan('hrms:pull-attendance', ['--all' => true, '--before-cutoff' => true])
            ->expectsOutputToContain('No company was due')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_all_reads_a_company_inside_its_pre_cutoff_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 10:57:00', 'Asia/Kolkata'));

        $this->fakeVendor([$this->row('hr-1', false), $this->row('hr-2', true)]);

        $this->artisan('hrms:pull-attendance', ['--all' => true, '--before-cutoff' => true])
            ->expectsOutputToContain('ACME01')
            ->assertSuccessful();
    }

    /**
     * Once per window, not once per minute. The scheduler runs every minute so
     * the read happens promptly, not five times.
     */
    public function test_all_does_not_read_the_same_company_twice_in_one_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 10:57:00', 'Asia/Kolkata'));

        $this->fakeVendor([$this->row('hr-1', false)]);

        $this->artisan('hrms:pull-attendance', ['--all' => true, '--before-cutoff' => true])->assertSuccessful();

        Carbon::setTestNow(Carbon::parse('2026-10-09 10:58:00', 'Asia/Kolkata'));

        $this->artisan('hrms:pull-attendance', ['--all' => true, '--before-cutoff' => true])
            ->expectsOutputToContain('No company was due')
            ->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_all_does_not_read_after_the_cutoff_has_passed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 11:05:00', 'Asia/Kolkata'));

        $this->artisan('hrms:pull-attendance', ['--all' => true, '--before-cutoff' => true])
            ->expectsOutputToContain('No company was due')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * The check that earns its place: running the scheduled argument string
     * through Artisan. A boolean option handed a value exits non-zero with
     * "does not accept a value" before any work happens, and that has happened
     * in this repository before - ['--all' => true] renders --all='1'.
     */
    public function test_the_scheduled_arguments_actually_run(): void
    {
        Http::preventStrayRequests();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'hrms:pull-attendance'));

        $this->assertNotNull($event);

        $args = trim(substr(
            $event->command,
            strpos($event->command, 'hrms:pull-attendance') + strlen('hrms:pull-attendance'),
        ));

        // 08:00 IST, so no company is in its window and nothing reaches the
        // network - preventStrayRequests enforces that.
        Carbon::setTestNow(Carbon::parse('2026-10-09 08:00:00', 'Asia/Kolkata'));

        $exit = Artisan::call('hrms:pull-attendance '.$args);
        $output = Artisan::output();

        $this->assertSame(0, $exit, "`hrms:pull-attendance {$args}` exited {$exit}: {$output}");
        $this->assertStringNotContainsString('does not accept a value', $output);
        $this->assertStringNotContainsString('is not defined', $output);
        $this->assertStringNotContainsString('does not exist', $output);
    }

    public function test_the_scheduler_runs_it_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'hrms:pull-attendance'));

        $this->assertCount(1, $events, 'the attendance read is not scheduled exactly once');

        $event = $events->first();

        $this->assertSame('* * * * *', $event->expression);
        // Bare flags, not ['--all' => true]: the latter renders --all='1' and
        // Symfony refuses a value on a boolean option, so every run would fail
        // before the command started. That has happened here before.
        $this->assertStringContainsString('--all', $event->command);
        $this->assertStringContainsString('--before-cutoff', $event->command);
    }
}
