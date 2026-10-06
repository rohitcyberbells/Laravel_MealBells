<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\RecurringSkip;
use App\Models\Skip;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The page already had the today card and recurring rules. These cover the two
 * things the controller was feeding it that nothing rendered: the week strip and
 * the cutoff countdown.
 */
class EmployeeDashboardStripTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $employeeUser;

    protected User $admin;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->admin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        $this->employeeUser = User::create([
            'name' => 'Alice', 'email' => 'alice@acme.test', 'password' => bcrypt('password'),
            'role' => 'employee', 'company_id' => $this->company->id, 'login_code' => 'ACME001',
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'user_id' => $this->employeeUser->id,
            'employee_code' => 'ACME001', 'name' => 'Alice',
            'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    /** @return array<string, mixed> */
    protected function props(): array
    {
        $user = User::findOrFail($this->employeeUser->id);

        $response = $this->actingAs($user)->get('/employee/dashboard');
        $response->assertStatus(200);

        return $response->getOriginalContent()->getData()['page']['props'];
    }

    public function test_the_page_loads_with_a_seven_day_strip(): void
    {
        $props = $this->props();

        $this->assertCount(7, $props['next_7_days']);
        $this->assertEquals('2026-10-05', $props['next_7_days'][0]['date']);
        $this->assertEquals('Monday', $props['next_7_days'][0]['day_name']);
    }

    public function test_the_strip_marks_weekends_as_non_meal_days(): void
    {
        $byDate = collect($this->props()['next_7_days'])->keyBy('date');

        $this->assertTrue($byDate['2026-10-09']['is_meal_day']);
        $this->assertFalse($byDate['2026-10-10']['is_meal_day']);
        $this->assertFalse($byDate['2026-10-11']['is_meal_day']);
    }

    public function test_the_cutoff_countdown_is_supplied_and_runs_out_after_the_cutoff(): void
    {
        $this->assertGreaterThan(0, $this->props()['today']['seconds_left']);
        $this->assertEquals('11:00', $this->props()['today']['cutoff_time']);

        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'Asia/Kolkata'));

        $this->assertEquals(0, $this->props()['today']['seconds_left']);
    }

    public function test_tapping_a_day_in_the_strip_records_a_self_skip(): void
    {
        $this->actingAs($this->employeeUser)
            ->post('/employee/skips', ['date' => '2026-10-06'])
            ->assertSessionHasNoErrors();

        $byDate = collect($this->props()['next_7_days'])->keyBy('date');

        $this->assertEquals('skipped', $byDate['2026-10-06']['status']);
        $this->assertEquals('self', $byDate['2026-10-06']['skip_source']);
        $this->assertTrue($byDate['2026-10-06']['can_cancel']);
    }

    public function test_taking_a_day_back_cancels_the_skip(): void
    {
        $this->actingAs($this->employeeUser)->post('/employee/skips', ['date' => '2026-10-06']);

        $skip = Skip::sole();

        $this->actingAs($this->employeeUser)
            ->delete("/employee/skips/{$skip->id}")
            ->assertSessionHasNoErrors();

        $this->assertNotNull($skip->fresh()->cancelled_at);
        $this->assertEquals(
            'take',
            collect($this->props()['next_7_days'])->keyBy('date')['2026-10-06']['status']
        );
    }

    public function test_an_hr_skip_cannot_be_taken_back_from_the_strip(): void
    {
        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'date' => '2026-10-06', 'source' => 'hr', 'reason' => 'HR decided',
            'created_by' => $this->admin->id,
        ]);

        $byDate = collect($this->props()['next_7_days'])->keyBy('date');

        // The strip shows it, and says to ask HR rather than offering a button.
        $this->assertEquals('skipped', $byDate['2026-10-06']['status']);
        $this->assertEquals('hr', $byDate['2026-10-06']['skip_source']);
        $this->assertFalse($byDate['2026-10-06']['can_cancel']);

        $skip = Skip::sole();

        $this->actingAs($this->employeeUser)
            ->delete("/employee/skips/{$skip->id}")
            ->assertSessionHasErrors('skip');

        $this->assertNull($skip->fresh()->cancelled_at);
    }

    public function test_a_recurring_skip_can_be_taken_back_from_the_strip(): void
    {
        RecurringSkip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'weekday' => 2, 'starts_on' => '2026-10-05', 'active' => true,
        ]);

        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'date' => '2026-10-06', 'source' => 'recurring',
        ]);

        $byDate = collect($this->props()['next_7_days'])->keyBy('date');

        // Unlike an HR skip, this one is the employee's own standing choice.
        $this->assertTrue($byDate['2026-10-06']['can_cancel']);
    }
}
