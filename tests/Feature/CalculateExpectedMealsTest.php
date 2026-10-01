<?php

namespace Tests\Feature;

use App\Actions\Meal\CalculateExpectedMeals;
use App\Actions\Meal\RecordExtraMeal;
use App\Actions\Meal\RecordSkip;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculateExpectedMealsTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_expected_meals_formula_and_breakdown(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => '123 Park', 'contact_phone' => '9876543210']);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        // Create 5 Active Eligible Employees
        for ($i = 1; $i <= 5; $i++) {
            Employee::create([
                'company_id' => $company->id,
                'employee_code' => "EMP0{$i}",
                'name' => "Employee {$i}",
                'status' => 'active',
                'is_meal_eligible' => true,
            ]);
        }

        // Create 1 Inactive Employee (Should not be counted in Base)
        Employee::create([
            'company_id' => $company->id,
            'employee_code' => 'EMP99',
            'name' => 'Inactive Staff',
            'status' => 'inactive',
            'is_meal_eligible' => true,
        ]);

        $date = Carbon::tomorrow()->toDateString();

        // Record 2 Skips (1 Leave, 1 WFH)

        $emp1 = Employee::where('employee_code', 'EMP01')->first();
        $emp2 = Employee::where('employee_code', 'EMP02')->first();

        (new RecordSkip)->execute($company, $emp1, $date, 'leave', 'On Leave', $user);
        (new RecordSkip)->execute($company, $emp2, $date, 'wfh', 'WFH Today', $user);

        // Record 3 Extra Meals
        (new RecordExtraMeal)->execute($company, $user, $date, 3, 'guest', 'Client Lunch');

        // Execute Calculation Action
        $calculator = new CalculateExpectedMeals;
        $result = $calculator->execute($company, $date);

        // Expected Formula: 5 (Base) + 3 (Extra) - 2 (Skips) = 6 Meals
        $this->assertEquals(5, $result['base_eligible_count']);
        $this->assertEquals(2, $result['skip_count']);
        $this->assertEquals(3, $result['extra_count']);
        $this->assertEquals(6, $result['final_expected_count']);

        // Check Breakdown
        $this->assertEquals(1, $result['breakdown']['leave']);
        $this->assertEquals(1, $result['breakdown']['wfh']);
        $this->assertEquals(0, $result['breakdown']['hr']);
    }
}
