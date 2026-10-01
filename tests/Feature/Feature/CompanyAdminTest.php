<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_dashboard_loads_today_stats_and_tenant_data(): void
    {
        $tiffin = TiffinService::create(['name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        CompanyTiffinAssignment::create(['company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true, 'assigned_at' => '2026-09-01']);
        CompanySetting::create(['company_id' => $company->id, 'cutoff_time' => '10:30:00', 'timezone' => 'Asia/Kolkata', 'meal_days' => [1, 2, 3, 4, 5]]);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);
        Employee::create(['company_id' => $company->id, 'employee_code' => 'EMP1', 'name' => 'Staff 1', 'status' => 'active', 'is_meal_eligible' => true]);

        $response = $this->actingAs($admin)->get(route('company-admin.dashboard'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('CompanyAdmin/Dashboard')
            ->has('todayStats')
            ->has('forecast', 7)
            ->where('company.name', 'Acme Corp')
        );
    }

    public function test_tenant_isolation_prevents_company_admin_from_editing_other_company_employee(): void
    {
        $companyA = Company::create(['name' => 'Company A', 'address' => 'Addr', 'contact_phone' => '1111111111']);
        $companyB = Company::create(['name' => 'Company B', 'address' => 'Addr', 'contact_phone' => '2222222222']);

        $adminA = User::create(['name' => 'Admin A', 'email' => 'admina@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $companyA->id]);
        $empB = Employee::create(['company_id' => $companyB->id, 'employee_code' => 'EMPB', 'name' => 'Bob', 'status' => 'active', 'is_meal_eligible' => true]);

        $response = $this->actingAs($adminA)->put(route('company-admin.employees.update', $empB->id), [
            'name' => 'Hacked Bob',
            'is_meal_eligible' => false,
            'status' => 'active',
        ]);

        $response->assertStatus(403);
    }

    public function test_company_setting_validation_rejects_invalid_meal_days(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Addr', 'contact_phone' => '9876543210']);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@acme.com', 'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id]);

        $response = $this->actingAs($admin)->put(route('company-admin.settings.update'), [
            'cutoff_time' => '10:30:00',
            'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => false,
            'meal_days' => [0, 8], // Invalid days
        ]);

        $response->assertSessionHasErrors(['meal_days.0', 'meal_days.1']);
    }
}
