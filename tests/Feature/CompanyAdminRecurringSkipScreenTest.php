<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\RecurringSkip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The controller, actions and tests for a company admin's recurring skips were
 * all in place and no page could reach them, so in practice only an employee
 * could set up "every Friday" - never their HR team.
 */
class CompanyAdminRecurringSkipScreenTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Company $other;

    protected User $admin;

    protected Employee $alice;

    protected Employee $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);
        $this->other = Company::create(['name' => 'Globex', 'code' => 'GLOBE1']);

        foreach ([$this->company, $this->other] as $company) {
            CompanySetting::create([
                'company_id' => $company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
                'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            ]);
        }

        $this->admin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        $this->alice = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'ACME001',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->bob = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'ACME002',
            'name' => 'Bob', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    protected function rule(Employee $employee, int $weekday = 5, bool $active = true): RecurringSkip
    {
        return RecurringSkip::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'weekday' => $weekday,
            'starts_on' => '2026-10-05',
            'active' => $active,
            'created_by' => $this->admin->id,
        ]);
    }

    /** @return array<string, mixed> */
    protected function rosterProps(): array
    {
        return $this->actingAs($this->admin)->get('/company-admin/employees')
            ->getOriginalContent()->getData()['page']['props'];
    }

    public function test_the_roster_carries_each_employees_rules(): void
    {
        $aliceRule = $this->rule($this->alice, weekday: 5);
        $bobRule = $this->rule($this->bob, weekday: 1);

        $this->actingAs($this->admin)->get('/company-admin/employees')
            ->assertInertia(fn (Assert $page) => $page
                ->component('CompanyAdmin/Employees/Index')
                // Keyed by employee id, exactly as the page indexes it.
                ->has("recurring_rules.{$this->alice->id}", 1)
                ->has("recurring_rules.{$this->bob->id}", 1)
                ->etc()
            );

        $rules = $this->rosterProps()['recurring_rules'];

        $this->assertEquals($aliceRule->id, $rules[$this->alice->id][0]['id']);
        $this->assertEquals(5, $rules[$this->alice->id][0]['weekday']);
        $this->assertEquals($bobRule->id, $rules[$this->bob->id][0]['id']);
    }

    /**
     * The modal lists every field it renders, so a missing column would show as
     * a blank line rather than a rule nobody can read.
     */
    public function test_a_rule_carries_what_the_modal_shows(): void
    {
        RecurringSkip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->alice->id,
            'weekday' => 3, 'starts_on' => '2026-10-07', 'ends_on' => '2026-12-31',
            'active' => false, 'created_by' => $this->admin->id,
        ]);

        $rule = $this->rosterProps()['recurring_rules'][$this->alice->id][0];

        foreach (['id', 'employee_id', 'weekday', 'starts_on', 'ends_on', 'active'] as $key) {
            $this->assertArrayHasKey($key, $rule);
        }

        $this->assertEquals(3, $rule['weekday']);
        $this->assertEquals('2026-12-31', $rule['ends_on']);
        $this->assertFalse((bool) $rule['active']);
    }

    public function test_an_employee_with_no_rules_is_simply_absent(): void
    {
        $this->rule($this->alice);

        $rules = $this->rosterProps()['recurring_rules'];

        // The page falls back to an empty list, so an absent key is fine.
        $this->assertArrayNotHasKey($this->bob->id, $rules);
    }

    public function test_the_admin_can_create_a_rule_from_the_roster(): void
    {
        $this->actingAs($this->admin)->from('/company-admin/employees')
            ->post("/company-admin/employees/{$this->alice->id}/recurring-skips", [
                'weekday' => 5,
                'starts_on' => '2026-10-09',
                'ends_on' => null,
            ])
            ->assertRedirect('/company-admin/employees')
            ->assertSessionHasNoErrors();

        $rule = RecurringSkip::where('employee_id', $this->alice->id)->sole();

        $this->assertEquals(5, $rule->weekday);
        $this->assertTrue($rule->active);
        $this->assertNull($rule->ends_on);
        $this->assertEquals($this->admin->id, $rule->created_by);
    }

    public function test_a_rule_the_engine_refuses_comes_back_as_an_error(): void
    {
        // starts_on in the past, which CreateRecurringSkip rejects.
        $this->actingAs($this->admin)->from('/company-admin/employees')
            ->post("/company-admin/employees/{$this->alice->id}/recurring-skips", [
                'weekday' => 5,
                'starts_on' => '2020-01-01',
            ])
            ->assertSessionHasErrors('recurring');

        $this->assertDatabaseCount('recurring_skips', 0);
    }

    public function test_the_admin_can_pause_and_resume_a_rule(): void
    {
        $rule = $this->rule($this->alice);

        $this->actingAs($this->admin)->from('/company-admin/employees')
            ->patch("/company-admin/employees/{$this->alice->id}/recurring-skips/{$rule->id}", ['active' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($rule->fresh()->active);

        $this->actingAs($this->admin)->from('/company-admin/employees')
            ->patch("/company-admin/employees/{$this->alice->id}/recurring-skips/{$rule->id}", ['active' => true]);
        $this->assertTrue($rule->fresh()->active);
    }

    public function test_the_admin_can_delete_a_rule(): void
    {
        $rule = $this->rule($this->alice);

        $this->actingAs($this->admin)->from('/company-admin/employees')
            ->delete("/company-admin/employees/{$this->alice->id}/recurring-skips/{$rule->id}")
            ->assertRedirect('/company-admin/employees');

        $this->assertDatabaseMissing('recurring_skips', ['id' => $rule->id]);
    }

    /**
     * The rule id is routed under an employee, so a mismatched pair has to be a
     * 404 rather than an action on whichever rule the id happened to name.
     */
    public function test_a_rule_cannot_be_reached_through_the_wrong_employee(): void
    {
        $bobsRule = $this->rule($this->bob);

        $this->actingAs($this->admin)
            ->patch("/company-admin/employees/{$this->alice->id}/recurring-skips/{$bobsRule->id}", ['active' => false])
            ->assertStatus(404);

        $this->assertTrue($bobsRule->fresh()->active);
    }

    public function test_an_admin_cannot_touch_another_companys_employee(): void
    {
        $outsider = Employee::create([
            'company_id' => $this->other->id, 'employee_code' => 'GLOBE001',
            'name' => 'Carol', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->actingAs($this->admin)
            ->post("/company-admin/employees/{$outsider->id}/recurring-skips", [
                'weekday' => 5, 'starts_on' => '2026-10-09',
            ])
            ->assertStatus(404);

        $this->assertDatabaseCount('recurring_skips', 0);
    }

    /**
     * Another company's rules must not ride along on the roster either, even
     * though the page only ever indexes by its own employees' ids.
     */
    public function test_the_roster_never_carries_another_companys_rules(): void
    {
        $outsider = Employee::create([
            'company_id' => $this->other->id, 'employee_code' => 'GLOBE001',
            'name' => 'Carol', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
        $this->rule($outsider);

        $rules = $this->rosterProps()['recurring_rules'];

        $this->assertArrayNotHasKey($outsider->id, $rules);
        $this->assertCount(0, $rules);
    }

    /**
     * The roster is paginated, so the rules have to follow the page rather than
     * loading every rule in the company to render fifteen rows.
     */
    public function test_only_the_rules_for_this_page_are_loaded(): void
    {
        for ($i = 3; $i <= 20; $i++) {
            $employee = Employee::create([
                'company_id' => $this->company->id, 'employee_code' => 'ACME'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => 'Employee '.$i, 'status' => 'active', 'is_meal_eligible' => true,
            ]);
            $this->rule($employee);
        }

        $props = $this->rosterProps();
        $pageIds = collect($props['employees']['data'])->pluck('id');

        // 20 employees, 18 of them with a rule, 15 rows to a page.
        $this->assertEquals(18, RecurringSkip::count());
        $this->assertCount(15, $pageIds);

        $firstPageRuleIds = collect($props['recurring_rules'])->keys()->map(fn ($id) => (int) $id);
        $this->assertEquals($pageIds->intersect($firstPageRuleIds)->sort()->values(), $firstPageRuleIds->sort()->values());
        $this->assertLessThan(18, $firstPageRuleIds->count());

        // And the second page brings its own, so no row is left without them.
        $secondPage = $this->actingAs($this->admin)->get('/company-admin/employees?page=2')
            ->getOriginalContent()->getData()['page']['props'];

        $secondPageIds = collect($secondPage['employees']['data'])->pluck('id');
        $this->assertCount(5, $secondPageIds);

        $secondPageRuleIds = collect($secondPage['recurring_rules'])->keys()->map(fn ($id) => (int) $id);
        $this->assertEquals($secondPageIds->intersect($secondPageRuleIds)->sort()->values(), $secondPageRuleIds->sort()->values());

        // Between them the two pages account for every rule, so paginating
        // hides none of them.
        $this->assertCount(18, $firstPageRuleIds->merge($secondPageRuleIds)->unique());
    }

    /**
     * The page and the controller have to agree on the prop and the endpoints;
     * a rename on either side would otherwise only surface when an admin
     * actually opened the modal - which is how this stayed invisible.
     */
    public function test_the_page_reads_the_prop_and_reaches_the_endpoints(): void
    {
        $index = file_get_contents(resource_path('js/Pages/CompanyAdmin/Employees/Index.vue'));
        $modal = file_get_contents(resource_path('js/Pages/CompanyAdmin/Employees/RecurringSkipModal.vue'));

        $this->assertStringContainsString('recurring_rules', $index);
        $this->assertStringContainsString('<RecurringSkipModal', $index);

        $this->assertStringContainsString('/recurring-skips', $modal);
        $this->assertStringContainsString('router.patch', $modal);
        $this->assertStringContainsString('router.delete', $modal);
        // Explicit state, as the endpoint requires.
        $this->assertStringContainsString('active: !rule.active', $modal);

        $this->assertArrayHasKey('recurring_rules', $this->rosterProps());
    }
}
