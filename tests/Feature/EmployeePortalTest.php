<?php

namespace Tests\Feature;

use App\Actions\Employee\CreateEmployeeLogins;
use App\Actions\Meal\CalculateExpectedMeals;
use App\Actions\Meal\RecordSkip;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealCount;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmployeePortalTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $adminA;

    protected Employee $empA1;

    protected Employee $empA2;

    protected Employee $empB1;

    protected User $empUserA1;

    protected TiffinService $tiffinService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);
        $this->companyB = Company::create(['name' => 'Beta Corp', 'code' => 'BETA1']);

        CompanySetting::create([
            'company_id' => $this->companyA->id,
            'cutoff_time' => '11:00:00',
            'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true,
            'meal_days' => [1, 2, 3, 4, 5],
        ]);

        CompanySetting::create([
            'company_id' => $this->companyB->id,
            'cutoff_time' => '11:00:00',
            'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true,
            'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->tiffinService = TiffinService::create([
            'name' => 'Best Tiffin',
            'email' => 'best@tiffin.com',
            'phone' => '9998887776',
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffinService->id,
            'is_active' => true,
        ]);

        $this->adminA = User::create([
            'name' => 'Admin Alpha',
            'email' => 'admin@alpha.com',
            'password' => bcrypt('password'),
            'role' => 'company_admin',
            'company_id' => $this->companyA->id,
        ]);

        $this->empA1 = Employee::create([
            'company_id' => $this->companyA->id,
            'employee_code' => 'EMP101',
            'name' => 'Alice Worker',
            'email' => 'alice@alpha.com',
            'status' => 'active',
            'is_meal_eligible' => true,
        ]);

        $this->empA2 = Employee::create([
            'company_id' => $this->companyA->id,
            'employee_code' => 'EMP102',
            'name' => 'Aaron Staff',
            'email' => 'aaron@alpha.com',
            'status' => 'active',
            'is_meal_eligible' => true,
        ]);

        $this->empB1 = Employee::create([
            'company_id' => $this->companyB->id,
            'employee_code' => 'EMP201',
            'name' => 'Bob Worker',
            'email' => 'bob@beta.com',
            'status' => 'active',
            'is_meal_eligible' => true,
        ]);

        $action = new CreateEmployeeLogins;
        $creds = $action->execute($this->companyA, [$this->empA1->id]);
        $this->empA1->refresh();
        $this->empUserA1 = $this->empA1->user;
        $this->empUserA1->update(['must_change_password' => false]);
    }

    public function test_logins_generated_only_for_own_company_employees(): void
    {
        $action = new CreateEmployeeLogins;

        $credentials = $action->execute($this->companyA, null, $this->adminA);

        $this->assertCount(1, $credentials); // Only empA2 was missing user_id
        $this->assertEquals('EMP102', $credentials[0]['employee_code']);
    }

    public function test_temp_password_is_hashed_in_db_and_flashed_once(): void
    {
        $action = new CreateEmployeeLogins;
        $creds = $action->execute($this->companyA, [$this->empA2->id]);

        $rawPass = $creds[0]['temporary_password'];
        $user = User::where('login_code', 'EMP102')->first();

        $this->assertNotNull($user);
        $this->assertTrue(Hash::check($rawPass, $user->password));
        $this->assertTrue($user->must_change_password);
    }

    public function test_employee_login_with_company_code_and_login_code(): void
    {
        $action = new CreateEmployeeLogins;
        $creds = $action->execute($this->companyA, [$this->empA2->id]);
        $rawPass = $creds[0]['temporary_password'];

        $response = $this->post('/login', [
            'company_code' => 'ALPHA1',
            'login_code' => 'EMP102',
            'password' => $rawPass,
        ]);

        $response->assertRedirect('/employee/dashboard');
        $this->assertAuthenticatedAs(User::where('login_code', 'EMP102')->first());
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $response = $this->post('/login', [
            'company_code' => 'ALPHA1',
            'login_code' => 'EMP101',
            'password' => 'wrong_password',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_inactive_employee_cannot_login_and_active_session_rejected(): void
    {
        // Deactivate employee
        $this->empA1->update(['status' => 'inactive']);

        // Login attempt should fail
        $response = $this->post('/login', [
            'company_code' => 'ALPHA1',
            'login_code' => 'EMP101',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('login_code');

        // Active session check
        $res2 = $this->actingAs($this->empUserA1)->get('/employee/dashboard');
        $res2->assertStatus(403);
    }

    public function test_employee_cannot_access_company_admin_routes(): void
    {
        $response = $this->actingAs($this->empUserA1)->get('/company-admin/dashboard');
        $response->assertStatus(403);
    }

    public function test_employee_skip_creation_affects_calculate_expected_meals(): void
    {
        // Total base eligible = 2
        $calculator = new CalculateExpectedMeals;
        $calcBefore = $calculator->execute($this->companyA, '2026-10-12');
        $this->assertEquals(2, $calcBefore['final_expected_count']);

        // Employee A1 creates a skip
        $response = $this->actingAs($this->empUserA1)->post('/employee/skips', [
            'date' => '2026-10-12',
            'reason' => 'Doctor appointment',
        ]);

        $response->assertSessionHasNoErrors();

        $calcAfter = $calculator->execute($this->companyA, '2026-10-12');
        $this->assertEquals(1, $calcAfter['final_expected_count']);
    }

    public function test_employee_cannot_delete_another_employees_skip(): void
    {
        // Create skip for empA2
        $action = new RecordSkip;
        $res = $action->execute($this->companyA, $this->empA2, '2026-10-12', 'self');

        // empUserA1 tries to delete empA2's skip
        $response = $this->actingAs($this->empUserA1)->delete("/employee/skips/{$res->skip->id}");

        $response->assertStatus(404);
    }

    public function test_employee_cannot_delete_hr_or_system_skip(): void
    {
        // HR creates skip for empA1
        $action = new RecordSkip;
        $res = $action->execute($this->companyA, $this->empA1, '2026-10-12', 'hr');

        // Employee attempts to delete HR skip -> friendly error
        $response = $this->actingAs($this->empUserA1)->delete("/employee/skips/{$res->skip->id}");

        $response->assertSessionHasErrors('skip');
    }

    public function test_employee_skip_rejected_when_locked(): void
    {
        // Create locked count for date
        MealCount::create([
            'company_id' => $this->companyA->id,
            'tiffin_service_id' => $this->tiffinService->id,
            'date' => '2026-10-12',
            'base_eligible_count' => 2,
            'skip_count' => 0,
            'extra_count' => 0,
            'final_expected_count' => 2,
            'adjusted_total' => 2,
            'breakdown' => [],
            'status' => 'confirmed',
            'locked_at' => now(),
        ]);

        $response = $this->actingAs($this->empUserA1)->post('/employee/skips', [
            'date' => '2026-10-12',
        ]);

        $response->assertSessionHasErrors('skip');
    }

    public function test_employee_skip_range_skips_non_meal_days(): void
    {
        // 2026-10-10 is Saturday, 2026-10-11 is Sunday, 2026-10-12 is Monday
        $response = $this->actingAs($this->empUserA1)->post('/employee/skips/range', [
            'from_date' => '2026-10-10',
            'to_date' => '2026-10-12',
            'reason' => 'Long Weekend',
        ]);

        $response->assertSessionHasNoErrors();

        // Skips created ONLY for 2026-10-12 (Monday)
        $this->assertDatabaseCount('skips', 1);
        $this->assertDatabaseHas('skips', [
            'employee_id' => $this->empA1->id,
            'date' => '2026-10-12',
        ]);
    }

    public function test_employee_dashboard_props_strictly_isolated(): void
    {
        // Create skip for empA2
        $action = new RecordSkip;
        $action->execute($this->companyA, $this->empA2, '2026-10-12', 'self');

        $response = $this->actingAs($this->empUserA1)->get('/employee/dashboard?date=2026-10-12');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Employee/Dashboard')
            ->where('today.status', 'take') // EmpA1 has NOT skipped
            ->has('my_skips', 0)
        );
    }
}
