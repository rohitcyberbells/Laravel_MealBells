<?php

namespace Tests\Feature;

use App\Actions\Employee\ImportEmployeeCsv;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealCount;
use App\Models\RecurringSkip;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An employee who stops being eligible must stop generating meals, by both
 * routes that can flip them: a direct edit and a CSV import.
 */
class IneligibleEmployeeRecurringSkipTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected User $adminA;

    protected Employee $empA1;

    protected TiffinService $tiffin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tiffin = TiffinService::create(['name' => 'Tiffin Co']);
        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        $this->adminA = User::create([
            'name' => 'Admin A', 'email' => 'admin@a.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyA->id,
        ]);

        CompanySetting::create([
            'company_id' => $this->companyA->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            'primary_admin_id' => $this->adminA->id,
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $this->companyA->id, 'tiffin_service_id' => $this->tiffin->id,
            'is_active' => true, 'assigned_at' => '2026-09-01',
        ]);

        $this->empA1 = Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP101',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    protected function activeRule(): RecurringSkip
    {
        return RecurringSkip::create([
            'company_id' => $this->companyA->id,
            'employee_id' => $this->empA1->id,
            'weekday' => 1,
            'starts_on' => '2026-10-05',
            'active' => true,
            'created_by' => $this->adminA->id,
        ]);
    }

    protected function futureRecurringSkip(string $date = '2026-10-12'): Skip
    {
        return Skip::create([
            'company_id' => $this->companyA->id,
            'employee_id' => $this->empA1->id,
            'date' => $date,
            'source' => 'recurring',
        ]);
    }

    public function test_marking_an_employee_inactive_pauses_the_rule_and_cancels_future_skips(): void
    {
        $rule = $this->activeRule();
        $skip = $this->futureRecurringSkip();

        $this->empA1->update(['status' => 'inactive']);

        $this->assertFalse($rule->fresh()->active);
        $this->assertNotNull($skip->fresh()->cancelled_at);
    }

    public function test_marking_an_employee_meal_ineligible_does_the_same(): void
    {
        $rule = $this->activeRule();
        $skip = $this->futureRecurringSkip();

        // Still active, just not eligible for meals - which the engine treats
        // exactly like inactive when deciding whether to feed someone.
        $this->empA1->update(['is_meal_eligible' => false]);

        $this->assertFalse($rule->fresh()->active);
        $this->assertNotNull($skip->fresh()->cancelled_at);
    }

    public function test_a_csv_import_marking_meal_ineligible_takes_the_same_path(): void
    {
        $rule = $this->activeRule();
        $skip = $this->futureRecurringSkip();

        $result = (new ImportEmployeeCsv)->execute($this->companyA, [
            [
                'employee_code' => 'EMP101',
                'name' => 'Alice',
                'is_meal_eligible' => false,
            ],
        ]);

        $this->assertEquals(1, $result['updated']);
        $this->assertFalse($rule->fresh()->active);
        $this->assertNotNull($skip->fresh()->cancelled_at);
    }

    public function test_a_csv_import_marking_inactive_takes_the_same_path(): void
    {
        $rule = $this->activeRule();
        $skip = $this->futureRecurringSkip();

        (new ImportEmployeeCsv)->execute($this->companyA, [
            ['employee_code' => 'EMP101', 'name' => 'Alice', 'status' => 'inactive'],
        ]);

        $this->assertFalse($rule->fresh()->active);
        $this->assertNotNull($skip->fresh()->cancelled_at);
    }

    public function test_only_recurring_skips_are_cancelled(): void
    {
        $this->activeRule();

        $recurring = $this->futureRecurringSkip('2026-10-12');

        $hrSkip = Skip::create([
            'company_id' => $this->companyA->id, 'employee_id' => $this->empA1->id,
            'date' => '2026-10-13', 'source' => 'hr', 'reason' => 'HR decided',
        ]);

        $this->empA1->update(['is_meal_eligible' => false]);

        $this->assertNotNull($recurring->fresh()->cancelled_at);

        // HR's own entry is not this rule's to withdraw.
        $this->assertNull($hrSkip->fresh()->cancelled_at);
    }

    public function test_a_locked_day_keeps_its_skip(): void
    {
        $this->activeRule();
        $skip = $this->futureRecurringSkip('2026-10-12');

        MealCount::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffin->id,
            'date' => '2026-10-12',
            'base_eligible_count' => 1, 'skip_count' => 1, 'extra_count' => 0,
            'final_expected_count' => 0, 'breakdown' => [],
            'status' => 'auto_confirmed', 'locked_at' => now(),
        ]);

        $this->empA1->update(['status' => 'inactive']);

        // The company already committed to this count.
        $this->assertNull($skip->fresh()->cancelled_at);
    }

    public function test_making_an_employee_eligible_again_does_not_reactivate_the_rule(): void
    {
        $rule = $this->activeRule();

        $this->empA1->update(['is_meal_eligible' => false]);
        $this->assertFalse($rule->fresh()->active);

        $this->empA1->update(['is_meal_eligible' => true]);

        // Re-enabling someone must not silently resume skipping their meals.
        $this->assertFalse($rule->fresh()->active);
    }
}
