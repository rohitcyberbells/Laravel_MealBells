<?php

namespace Tests\Feature;

use App\Actions\Meal\RecordExtraMeal;
use App\Actions\Meal\RecordSkip;
use App\Enums\SkipOutcome;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkipAndAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_skip_action_creates_and_preserves_first_source(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $employee = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP01', 'name' => 'Rahul']);
        $user = User::create(['name' => 'HR Admin', 'email' => 'hr@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        $action = new RecordSkip;
        $date = Carbon::tomorrow()->toDateString();

        // First call -> outcome: created
        $result = $action->execute($company, $employee, $date, 'hr', 'WFH Today', $user);
        $skip = $result->skip;

        $this->assertEquals(SkipOutcome::CREATED, $result->outcome);
        $this->assertEquals($company->id, $skip->company_id);
        $this->assertEquals($employee->id, $skip->employee_id);
        $this->assertEquals('hr', $skip->source);
        $this->assertEquals('WFH Today', $skip->reason);

        // Record again for same employee & date -> First Source Wins (outcome: already_skipped, source remains 'hr')
        $secondResult = $action->execute($company, $employee, $date, 'leave', 'On Sick Leave', $user);
        $secondSkip = $secondResult->skip;

        $this->assertEquals(SkipOutcome::ALREADY_SKIPPED, $secondResult->outcome);
        $this->assertEquals($skip->id, $secondSkip->id);
        $this->assertEquals('hr', $secondSkip->source); // Preserves original source!
    }

    public function test_record_skip_prevents_cross_company_employee_assignment(): void
    {
        $companyA = Company::create(['name' => 'Company A', 'address' => 'Addr A', 'contact_phone' => '1111111111']);
        $companyB = Company::create(['name' => 'Company B', 'address' => 'Addr B', 'contact_phone' => '2222222222']);
        $employeeA = Employee::create(['company_id' => $companyA->id, 'employee_code' => 'EMPA', 'name' => 'Staff A']);

        $action = new RecordSkip;

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Employee does not belong to this company.');

        $action->execute($companyB, $employeeA, Carbon::tomorrow()->toDateString());
    }

    public function test_record_extra_meal_action_creates_adjustment(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $user = User::create(['name' => 'HR Admin', 'email' => 'hr@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        $action = new RecordExtraMeal;
        $date = Carbon::tomorrow()->toDateString();

        $adjustment = $action->execute($company, $user, $date, 5, 'guest', 'Client Meeting');

        $this->assertEquals(5, $adjustment->quantity);
        $this->assertEquals('guest', $adjustment->type);
        $this->assertEquals('Client Meeting', $adjustment->reason);
        $this->assertEquals($user->id, $adjustment->created_by);
    }

    public function test_record_extra_meal_requires_positive_quantity(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $user = User::create(['name' => 'HR Admin', 'email' => 'hr@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        $action = new RecordExtraMeal;

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Quantity must be a positive integer.');

        $action->execute($company, $user, Carbon::tomorrow()->toDateString(), 0);
    }

    public function test_cutoff_guard_rejects_skip_after_cutoff_time(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $employee = Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP01', 'name' => 'Rahul']);

        // Set cutoff time to 00:01:00 (which has already passed for today)
        CompanySetting::create([
            'company_id' => $company->id,
            'cutoff_time' => '00:01:00',
            'timezone' => 'Asia/Kolkata',
        ]);

        $action = new RecordSkip;

        $this->expectException(Exception::class);
        // The message carries the cutoff as a person reads it, not as the
        // column stores it.
        $this->expectExceptionMessage('Cutoff time (00:01) has passed for today.');

        $action->execute($company, $employee, Carbon::today()->toDateString());
    }
}
