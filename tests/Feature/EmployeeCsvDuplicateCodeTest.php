<?php

namespace Tests\Feature;

use App\Actions\Employee\ImportEmployeeCsv;
use App\Actions\Employee\ValidateEmployeeCsv;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeCsvDuplicateCodeTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected ValidateEmployeeCsv $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);
        $this->validator = new ValidateEmployeeCsv;
    }

    /** @return array{valid_rows: array, errors: array} */
    protected function check(array $rows): array
    {
        return $this->validator->execute($rows, $this->company);
    }

    public function test_every_row_sharing_a_code_is_rejected(): void
    {
        $result = $this->check([
            ['employee_code' => 'QA001', 'name' => 'First'],
            ['employee_code' => 'QA001', 'name' => 'Second'],
        ]);

        // Previously the first row was accepted, so whichever came first in the
        // file silently won.
        $this->assertEmpty($result['valid_rows']);
        $this->assertCount(2, $result['errors']);
        $this->assertEqualsCanonicalizing([2, 3], array_column($result['errors'], 'row'));

        foreach ($result['errors'] as $error) {
            $this->assertEquals('employee_code', $error['field']);
            $this->assertStringContainsString("Duplicate employee code 'QA001'", $error['message']);
        }
    }

    public function test_nothing_from_a_duplicated_code_is_imported(): void
    {
        $result = $this->check([
            ['employee_code' => 'QA001', 'name' => 'First'],
            ['employee_code' => 'QA001', 'name' => 'Second'],
        ]);

        (new ImportEmployeeCsv)->execute($this->company, $result['valid_rows']);

        $this->assertEquals(0, Employee::count());
    }

    public function test_a_unique_row_in_the_same_file_still_imports(): void
    {
        $result = $this->check([
            ['employee_code' => 'QA001', 'name' => 'First'],
            ['employee_code' => 'QA001', 'name' => 'Second'],
            ['employee_code' => 'QA002', 'name' => 'Only One'],
        ]);

        $this->assertCount(1, $result['valid_rows']);
        $this->assertEquals('QA002', $result['valid_rows'][0]['employee_code']);

        (new ImportEmployeeCsv)->execute($this->company, $result['valid_rows']);

        $this->assertEquals(['QA002'], Employee::pluck('employee_code')->all());
    }

    public function test_the_duplicate_check_ignores_case_and_padding(): void
    {
        $result = $this->check([
            ['employee_code' => 'qa001', 'name' => 'First'],
            ['employee_code' => ' QA001 ', 'name' => 'Second'],
        ]);

        // Codes are normalised before counting, so these are the same employee.
        $this->assertEmpty($result['valid_rows']);
        $this->assertCount(2, $result['errors']);
    }

    public function test_three_rows_sharing_a_code_all_report(): void
    {
        $result = $this->check([
            ['employee_code' => 'QA001', 'name' => 'First'],
            ['employee_code' => 'QA001', 'name' => 'Second'],
            ['employee_code' => 'QA001', 'name' => 'Third'],
        ]);

        $this->assertEmpty($result['valid_rows']);
        $this->assertCount(3, $result['errors']);
    }

    public function test_a_row_with_no_code_is_not_counted_as_a_duplicate(): void
    {
        $result = $this->check([
            ['employee_code' => '', 'name' => 'No Code A'],
            ['employee_code' => '', 'name' => 'No Code B'],
            ['employee_code' => 'QA002', 'name' => 'Fine'],
        ]);

        $this->assertCount(1, $result['valid_rows']);

        // Both blank rows fail for a missing code, not for being duplicates.
        $fields = array_column($result['errors'], 'field');
        $this->assertEquals(['employee_code', 'employee_code'], $fields);

        foreach ($result['errors'] as $error) {
            $this->assertStringContainsString('required', $error['message']);
        }
    }

    public function test_importing_the_same_code_on_separate_uploads_still_updates(): void
    {
        // One code per file is not a duplicate; the second upload is an update.
        (new ImportEmployeeCsv)->execute($this->company, $this->check([
            ['employee_code' => 'QA001', 'name' => 'First'],
        ])['valid_rows']);

        (new ImportEmployeeCsv)->execute($this->company, $this->check([
            ['employee_code' => 'QA001', 'name' => 'Renamed'],
        ])['valid_rows']);

        $this->assertEquals(1, Employee::count());
        $this->assertEquals('Renamed', Employee::sole()->name);
    }
}
