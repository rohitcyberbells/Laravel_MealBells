<?php

namespace Tests\Feature;

use App\Actions\Employee\ImportEmployeeCsv;
use App\Actions\Employee\ValidateEmployeeCsv;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class EmployeeExternalIdCsvTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $adminA;

    protected ValidateEmployeeCsv $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);
        $this->companyB = Company::create(['name' => 'Beta Corp', 'code' => 'BETA1']);

        $this->adminA = User::create([
            'name' => 'Admin A', 'email' => 'admin@a.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyA->id,
        ]);

        $this->validator = new ValidateEmployeeCsv;
    }

    public function test_external_id_is_optional_and_absent_column_changes_nothing(): void
    {
        $result = $this->validator->execute([
            ['employee_code' => 'EMP101', 'name' => 'Alice'],
        ], $this->companyA);

        $this->assertEmpty($result['errors']);
        $this->assertArrayNotHasKey('external_id', $result['valid_rows'][0]);
    }

    public function test_external_id_is_carried_through_to_the_created_employee(): void
    {
        $result = $this->validator->execute([
            ['employee_code' => 'EMP101', 'name' => 'Alice', 'external_id' => 'HR-1'],
        ], $this->companyA);

        $this->assertEmpty($result['errors']);
        $this->assertEquals('HR-1', $result['valid_rows'][0]['external_id']);

        (new ImportEmployeeCsv)->execute($this->companyA, $result['valid_rows']);

        $this->assertEquals('HR-1', Employee::sole()->external_id);
    }

    public function test_a_blank_external_id_is_allowed_and_stored_as_null(): void
    {
        $result = $this->validator->execute([
            ['employee_code' => 'EMP101', 'name' => 'Alice', 'external_id' => ''],
            ['employee_code' => 'EMP102', 'name' => 'Bob', 'external_id' => ''],
        ], $this->companyA);

        // Two blanks are not a duplicate clash.
        $this->assertEmpty($result['errors']);

        (new ImportEmployeeCsv)->execute($this->companyA, $result['valid_rows']);

        $this->assertEquals(2, Employee::whereNull('external_id')->count());
    }

    public function test_duplicate_external_id_inside_the_csv_is_rejected(): void
    {
        $result = $this->validator->execute([
            ['employee_code' => 'EMP101', 'name' => 'Alice', 'external_id' => 'HR-1'],
            ['employee_code' => 'EMP102', 'name' => 'Bob', 'external_id' => 'HR-1'],
        ], $this->companyA);

        $this->assertCount(1, $result['valid_rows']);
        $this->assertEquals('external_id', $result['errors'][0]['field']);
        $this->assertStringContainsString('Duplicate external id', $result['errors'][0]['message']);
    }

    public function test_external_id_already_used_by_another_employee_in_the_company_is_rejected(): void
    {
        Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP999', 'external_id' => 'HR-1',
            'name' => 'Existing', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $result = $this->validator->execute([
            ['employee_code' => 'EMP101', 'name' => 'Alice', 'external_id' => 'HR-1'],
        ], $this->companyA);

        $this->assertEmpty($result['valid_rows']);
        $this->assertEquals('external_id', $result['errors'][0]['field']);
        $this->assertStringContainsString('already belongs to another employee', $result['errors'][0]['message']);
    }

    public function test_an_employee_keeping_its_own_external_id_is_an_update_not_a_clash(): void
    {
        Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP101', 'external_id' => 'HR-1',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $result = $this->validator->execute([
            ['employee_code' => 'EMP101', 'name' => 'Alice Renamed', 'external_id' => 'HR-1'],
        ], $this->companyA);

        $this->assertEmpty($result['errors']);

        (new ImportEmployeeCsv)->execute($this->companyA, $result['valid_rows']);

        $employee = Employee::sole();
        $this->assertEquals('Alice Renamed', $employee->name);
        $this->assertEquals('HR-1', $employee->external_id);
    }

    public function test_the_same_external_id_in_another_company_is_not_a_clash(): void
    {
        Employee::create([
            'company_id' => $this->companyB->id, 'employee_code' => 'EMP201', 'external_id' => 'HR-1',
            'name' => 'Carol', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $result = $this->validator->execute([
            ['employee_code' => 'EMP101', 'name' => 'Alice', 'external_id' => 'HR-1'],
        ], $this->companyA);

        $this->assertEmpty($result['errors']);
    }

    public function test_import_can_clear_an_external_id_by_sending_a_blank_column(): void
    {
        Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP101', 'external_id' => 'HR-1',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $result = $this->validator->execute([
            ['employee_code' => 'EMP101', 'name' => 'Alice', 'external_id' => ''],
        ], $this->companyA);

        (new ImportEmployeeCsv)->execute($this->companyA, $result['valid_rows']);

        $this->assertNull(Employee::sole()->external_id);
    }

    public function test_the_preview_endpoint_parses_an_uploaded_csv(): void
    {
        $csv = "employee_code,name,external_id\nEMP101,Alice,HR-1\nEMP102,Bob,HR-2\n";

        $response = $this->actingAs($this->adminA)->post('/company-admin/employees/csv-preview', [
            'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('csvPreview');

        $preview = session('csvPreview');
        $this->assertEmpty($preview['errors']);
        $this->assertCount(2, $preview['valid_rows']);
        $this->assertEquals('HR-1', $preview['valid_rows'][0]['external_id']);
    }

    public function test_the_preview_endpoint_reports_a_duplicate_external_id(): void
    {
        $csv = "employee_code,name,external_id\nEMP101,Alice,HR-1\nEMP102,Bob,HR-1\n";

        $this->actingAs($this->adminA)->post('/company-admin/employees/csv-preview', [
            'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
        ])->assertRedirect();

        $preview = session('csvPreview');
        $this->assertCount(1, $preview['valid_rows']);
        $this->assertEquals('external_id', $preview['errors'][0]['field']);
    }

    public function test_the_preview_endpoint_strips_a_utf8_bom_from_the_header(): void
    {
        $csv = "\xEF\xBB\xBFemployee_code,name,external_id\nEMP101,Alice,HR-1\n";

        $this->actingAs($this->adminA)->post('/company-admin/employees/csv-preview', [
            'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
        ])->assertRedirect();

        $preview = session('csvPreview');
        $this->assertEmpty($preview['errors']);
        $this->assertEquals('EMP101', $preview['valid_rows'][0]['employee_code']);
    }
}
