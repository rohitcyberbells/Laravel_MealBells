<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\RecurringSkip;
use App\Models\Skip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringSkipUpdateIdempotentTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected User $adminA;

    protected User $empUserA1;

    protected Employee $empA1;

    protected Employee $empA2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->companyA->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->adminA = User::create([
            'name' => 'Admin A', 'email' => 'admin@a.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyA->id,
        ]);

        $this->empUserA1 = User::create([
            'name' => 'Alice', 'email' => 'alice@a.test', 'password' => bcrypt('password'),
            'role' => 'employee', 'company_id' => $this->companyA->id, 'login_code' => 'EMP101',
        ]);

        $this->empA1 = Employee::create([
            'company_id' => $this->companyA->id, 'user_id' => $this->empUserA1->id,
            'employee_code' => 'EMP101', 'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->empA2 = Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP102',
            'name' => 'Bob', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    protected function rule(?Employee $employee = null, bool $active = true): RecurringSkip
    {
        return RecurringSkip::create([
            'company_id' => $this->companyA->id,
            'employee_id' => ($employee ?? $this->empA1)->id,
            'weekday' => 1,
            'starts_on' => '2026-10-05',
            'active' => $active,
            'created_by' => $this->adminA->id,
        ]);
    }

    public function test_pausing_twice_leaves_the_rule_paused(): void
    {
        $rule = $this->rule();

        $this->actingAs($this->empUserA1)
            ->patch("/employee/recurring-skips/{$rule->id}", ['active' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($rule->fresh()->active);

        // The second request used to toggle the rule back on, silently resuming
        // the employee's skipped meals.
        $this->actingAs($this->empUserA1)
            ->patch("/employee/recurring-skips/{$rule->id}", ['active' => false]);
        $this->assertFalse($rule->fresh()->active);
    }

    public function test_resuming_twice_leaves_the_rule_active(): void
    {
        $rule = $this->rule(active: false);

        $this->actingAs($this->empUserA1)
            ->patch("/employee/recurring-skips/{$rule->id}", ['active' => true]);
        $this->assertTrue($rule->fresh()->active);

        $this->actingAs($this->empUserA1)
            ->patch("/employee/recurring-skips/{$rule->id}", ['active' => true]);
        $this->assertTrue($rule->fresh()->active);
    }

    public function test_the_active_flag_is_required(): void
    {
        $rule = $this->rule();

        $this->actingAs($this->empUserA1)
            ->patch("/employee/recurring-skips/{$rule->id}", [])
            ->assertSessionHasErrors('active');

        $this->assertTrue($rule->fresh()->active);
    }

    public function test_pausing_cancels_future_recurring_skips_only_once(): void
    {
        $rule = $this->rule();

        $future = Skip::create([
            'company_id' => $this->companyA->id, 'employee_id' => $this->empA1->id,
            'date' => '2026-10-12', 'source' => 'recurring',
        ]);

        $this->actingAs($this->empUserA1)
            ->patch("/employee/recurring-skips/{$rule->id}", ['active' => false]);

        $cancelledAt = $future->fresh()->cancelled_at;
        $this->assertNotNull($cancelledAt);

        // A repeat pause is a no-op, so the audit timestamp does not move.
        $this->actingAs($this->empUserA1)
            ->patch("/employee/recurring-skips/{$rule->id}", ['active' => false]);

        $this->assertEquals($cancelledAt->toDateTimeString(), $future->fresh()->cancelled_at->toDateTimeString());
    }

    public function test_an_employee_cannot_update_another_employees_rule(): void
    {
        $othersRule = $this->rule($this->empA2);

        $this->actingAs($this->empUserA1)
            ->patch("/employee/recurring-skips/{$othersRule->id}", ['active' => false])
            ->assertStatus(404);

        $this->assertTrue($othersRule->fresh()->active);
    }

    public function test_the_company_admin_route_is_also_explicit(): void
    {
        $rule = $this->rule();

        $this->actingAs($this->adminA)
            ->patch("/company-admin/employees/{$this->empA1->id}/recurring-skips/{$rule->id}", ['active' => false]);
        $this->assertFalse($rule->fresh()->active);

        $this->actingAs($this->adminA)
            ->patch("/company-admin/employees/{$this->empA1->id}/recurring-skips/{$rule->id}", ['active' => false]);
        $this->assertFalse($rule->fresh()->active);
    }

    public function test_the_company_admin_route_requires_the_active_flag(): void
    {
        $rule = $this->rule();

        $this->actingAs($this->adminA)
            ->patch("/company-admin/employees/{$this->empA1->id}/recurring-skips/{$rule->id}", [])
            ->assertSessionHasErrors('active');

        $this->assertTrue($rule->fresh()->active);
    }
}
