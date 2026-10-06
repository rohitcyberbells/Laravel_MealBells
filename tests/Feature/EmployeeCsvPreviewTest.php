<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The preview route had no coverage, which is how it shipped handing an
 * UploadedFile to an action that takes an array of rows.
 */
class EmployeeCsvPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        $this->adminA = User::create([
            'name' => 'Admin A', 'email' => 'admin@a.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $company->id,
        ]);
    }

    protected function preview(string $csv)
    {
        return $this->actingAs($this->adminA)->post('/company-admin/employees/csv-preview', [
            'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
        ]);
    }

    public function test_it_parses_an_uploaded_csv_into_preview_rows(): void
    {
        $response = $this->preview("employee_code,name,email\nEMP101,Alice,alice@a.test\nEMP102,Bob,\n");

        $response->assertRedirect();
        $response->assertSessionHas('csvPreview');

        $preview = session('csvPreview');
        $this->assertEmpty($preview['errors']);
        $this->assertCount(2, $preview['valid_rows']);
        $this->assertEquals('EMP101', $preview['valid_rows'][0]['employee_code']);
        $this->assertEquals('alice@a.test', $preview['valid_rows'][0]['email']);
        $this->assertNull($preview['valid_rows'][1]['email']);
    }

    public function test_it_strips_a_utf8_bom_from_the_first_header(): void
    {
        // Excel writes this, and it used to make 'employee_code' unmatchable so
        // every row failed as missing a code.
        $response = $this->preview("\xEF\xBB\xBFemployee_code,name\nEMP101,Alice\n");

        $response->assertRedirect();

        $preview = session('csvPreview');
        $this->assertEmpty($preview['errors']);
        $this->assertEquals('EMP101', $preview['valid_rows'][0]['employee_code']);
    }

    public function test_it_reports_row_level_errors_and_ignores_blank_lines(): void
    {
        $response = $this->preview("employee_code,name\nEMP101,Alice\n\n,Missing Code\nEMP101,Duplicate\n");

        $response->assertRedirect();

        $preview = session('csvPreview');

        // Both EMP101 rows are rejected, not just the second: a duplicated code
        // makes every row carrying it ambiguous. With the blank-code row that
        // leaves nothing importable here.
        $this->assertCount(0, $preview['valid_rows']);

        $fields = collect($preview['errors'])->pluck('field')->all();
        $this->assertEquals(['employee_code', 'employee_code', 'employee_code'], $fields);
        $this->assertCount(3, $preview['errors']);
    }
}
