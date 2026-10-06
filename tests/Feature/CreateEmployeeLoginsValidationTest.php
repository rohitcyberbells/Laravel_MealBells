<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateEmployeeLoginsValidationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $adminA;

    protected Employee $empA1;

    protected Employee $empA2;

    protected Employee $empB1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);
        $this->companyB = Company::create(['name' => 'Beta Corp', 'code' => 'BETA1']);

        $this->adminA = User::create([
            'name' => 'Admin A', 'email' => 'admin@a.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyA->id,
        ]);

        $this->empA1 = Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP101', 'email' => 'a1@a.test',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->empA2 = Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP102', 'email' => 'a2@a.test',
            'name' => 'Bob', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->empB1 = Employee::create([
            'company_id' => $this->companyB->id, 'employee_code' => 'EMP201', 'email' => 'b1@b.test',
            'name' => 'Carol', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    protected function createLogins(array $payload = [])
    {
        return $this->actingAs($this->adminA)->post('/company-admin/employees/logins', $payload);
    }

    protected function employeeUserCount(): int
    {
        return User::where('role', 'employee')->count();
    }

    public function test_an_empty_request_no_longer_provisions_everyone(): void
    {
        $this->createLogins()->assertSessionHasErrors('employee_ids');

        $this->assertEquals(0, $this->employeeUserCount());
    }

    public function test_an_empty_array_is_rejected(): void
    {
        $this->createLogins(['employee_ids' => []])->assertSessionHasErrors('employee_ids');

        $this->assertEquals(0, $this->employeeUserCount());
    }

    public function test_another_companys_employee_id_is_rejected_rather_than_dropped(): void
    {
        $this->createLogins(['employee_ids' => [$this->empB1->id]])
            ->assertSessionHasErrors('employee_ids.0');

        $this->assertEquals(0, $this->employeeUserCount());
        $this->assertNull($this->empB1->fresh()->user_id);
    }

    public function test_a_mix_of_own_and_foreign_ids_provisions_nothing(): void
    {
        $this->createLogins(['employee_ids' => [$this->empA1->id, $this->empB1->id]])
            ->assertSessionHasErrors('employee_ids.1');

        $this->assertEquals(0, $this->employeeUserCount());
    }

    public function test_selected_employees_get_logins(): void
    {
        $this->createLogins(['employee_ids' => [$this->empA1->id]])
            ->assertSessionHasNoErrors();

        $this->assertEquals(1, $this->employeeUserCount());
        $this->assertNotNull($this->empA1->fresh()->user_id);

        // Only the one selected.
        $this->assertNull($this->empA2->fresh()->user_id);
    }

    public function test_the_generated_credentials_are_returned_once(): void
    {
        $this->createLogins(['employee_ids' => [$this->empA1->id, $this->empA2->id]])
            ->assertSessionHas('credentials');

        $credentials = session('credentials');

        $this->assertCount(2, $credentials);
        $this->assertArrayHasKey('temporary_password', $credentials[0]);
        $this->assertEqualsCanonicalizing(
            ['EMP101', 'EMP102'],
            collect($credentials)->pluck('employee_code')->all()
        );
    }
}
