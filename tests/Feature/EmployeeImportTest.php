<?php

namespace Tests\Feature;

use App\Actions\Employee\ImportEmployeeCsv;
use App\Actions\Employee\ValidateEmployeeCsv;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_and_import_pipeline(): void
    {
        $company = Company::create([
            'name' => 'Acme Corp',
            'address' => '123 Tech Park',
            'contact_phone' => '9876543210',
        ]);

        $validator = new ValidateEmployeeCsv;
        $importer = new ImportEmployeeCsv;

        $rawRows = [
            ['employee_code' => 'emp101', 'name' => 'Amit Kumar', 'email' => 'amit@acme.com', 'attendance_source' => 'manual'],
            ['employee_code' => 'emp102', 'name' => 'Suresh Verma', 'email' => ''],
            ['employee_code' => '', 'name' => 'Invalid Code'], // Error
            ['employee_code' => 'emp103', 'name' => 'Bad Email', 'email' => 'not-an-email'], // Error
            ['employee_code' => 'emp101', 'name' => 'Duplicate In Batch'], // Duplicate error

        ];

        $valResult = $validator->execute($rawRows);

        // emp101 appears twice, so BOTH of its rows are rejected rather than the
        // first one winning. That leaves emp102 as the only importable row, and
        // four errors: the blank code, the bad email, and one per emp101 row.
        $this->assertCount(1, $valResult['valid_rows']);
        $this->assertEquals('EMP102', $valResult['valid_rows'][0]['employee_code']);
        $this->assertCount(4, $valResult['errors']);

        // Persist valid rows
        $importResult = $importer->execute($company, $valResult['valid_rows']);

        $this->assertEquals(1, $importResult['imported']);
        $this->assertEquals(0, $importResult['updated']);
        $this->assertEquals(0, $importResult['unchanged']);

        $this->assertDatabaseHas('employees', [
            'company_id' => $company->id,
            'employee_code' => 'EMP102',
            'name' => 'Suresh Verma',
            'status' => 'active',
            'is_meal_eligible' => true,
        ]);

        // The ambiguous code landed nowhere, so no guess was persisted.
        $this->assertDatabaseMissing('employees', [
            'company_id' => $company->id,
            'employee_code' => 'EMP101',
        ]);
    }

    public function test_import_detects_updated_and_unchanged_records(): void
    {
        $company = Company::create([
            'name' => 'Acme Corp',
            'address' => '123 Tech Park',
            'contact_phone' => '9876543210',
        ]);

        Employee::create([
            'company_id' => $company->id,
            'employee_code' => 'EMP101',
            'name' => 'Amit Original',
            'email' => 'amit@acme.com',
            'attendance_source' => 'manual',
            'is_meal_eligible' => true,
            'status' => 'active',
        ]);

        $importer = new ImportEmployeeCsv;

        // Row 1: Same data (unchanged)
        // Row 2: Updated name (updated)
        $rows = [
            ['employee_code' => 'EMP101', 'name' => 'Amit Original', 'email' => 'amit@acme.com'],
            ['employee_code' => 'EMP102', 'name' => 'New Employee'],
        ];

        $result = $importer->execute($company, $rows);

        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(0, $result['updated']);
        $this->assertEquals(1, $result['unchanged']);
    }

    public function test_tenant_isolation_prevents_cross_company_overwrite(): void
    {
        $companyA = Company::create(['name' => 'Company A', 'address' => 'Addr A', 'contact_phone' => '1111111111']);
        $companyB = Company::create(['name' => 'Company B', 'address' => 'Addr B', 'contact_phone' => '2222222222']);

        Employee::create([
            'company_id' => $companyA->id,
            'employee_code' => 'EMP300',
            'name' => 'Company A Staff',
        ]);

        $importer = new ImportEmployeeCsv;

        $rows = [
            ['employee_code' => 'EMP300', 'name' => 'Company B Staff'],
        ];

        $result = $importer->execute($companyB, $rows);

        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(0, $result['updated']);

        $this->assertDatabaseHas('employees', [
            'company_id' => $companyA->id,
            'employee_code' => 'EMP300',
            'name' => 'Company A Staff',
        ]);

        $this->assertDatabaseHas('employees', [
            'company_id' => $companyB->id,
            'employee_code' => 'EMP300',
            'name' => 'Company B Staff',
        ]);
    }
}
