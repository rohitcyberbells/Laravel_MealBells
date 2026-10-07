<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The preview endpoint was already correct; what was missing was delivery. The
 * flash was set and never shared, so the page could not see it and the modal
 * stayed on its first step.
 */
class EmployeeCsvPreviewDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        $this->admin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);
    }

    protected function upload(string $csv)
    {
        return $this->actingAs($this->admin)
            ->from('/company-admin/employees')
            ->post('/company-admin/employees/csv-preview', [
                'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
            ]);
    }

    public function test_the_preview_arrives_in_the_page_props_after_an_upload(): void
    {
        $this->upload("employee_code,name,email\nQA001,QA One,qa1@acme.test\nQA002,QA Two,\n")
            ->assertRedirect('/company-admin/employees');

        $this->actingAs($this->admin)->get('/company-admin/employees')
            ->assertInertia(fn (Assert $page) => $page
                ->component('CompanyAdmin/Employees/Index')
                // Exactly the path the modal reads.
                ->has('flash.csvPreview.valid_rows', 2)
                ->has('flash.csvPreview.errors', 0)
                ->where('flash.csvPreview.valid_rows.0.employee_code', 'QA001')
                ->etc()
            );
    }

    public function test_the_preview_carries_the_reason_for_each_bad_row(): void
    {
        $this->upload("employee_code,name,email\nQA001,QA One,not-an-email\n,Missing Code,\n");

        $props = $this->actingAs($this->admin)->get('/company-admin/employees')
            ->getOriginalContent()->getData()['page']['props'];

        $errors = $props['flash']['csvPreview']['errors'];

        $this->assertCount(2, $errors);

        // Row, field and message, which is what the modal lists.
        foreach ($errors as $error) {
            $this->assertArrayHasKey('row', $error);
            $this->assertArrayHasKey('field', $error);
            $this->assertNotEmpty($error['message']);
        }

        $this->assertEqualsCanonicalizing(['email', 'employee_code'], array_column($errors, 'field'));
    }

    public function test_the_upload_alone_imports_nothing(): void
    {
        $this->upload("employee_code,name\nQA001,QA One\n");

        $this->assertEquals(0, Employee::count());
    }

    public function test_only_confirming_imports_the_previewed_rows(): void
    {
        $this->upload("employee_code,name\nQA001,QA One\nQA002,QA Two\n");

        $rows = $this->actingAs($this->admin)->get('/company-admin/employees')
            ->getOriginalContent()->getData()['page']['props']['flash']['csvPreview']['valid_rows'];

        $this->actingAs($this->admin)
            ->from('/company-admin/employees')
            ->post('/company-admin/employees/csv-import', ['rows' => $rows])
            // The status matters: assertSessionHasNoErrors passes on a 500,
            // because a 500 carries no validation errors - which is how an
            // "Array to string conversion" in the response went unnoticed while
            // the rows themselves imported fine.
            ->assertRedirect('/company-admin/employees')
            ->assertSessionHasNoErrors();

        $this->assertEquals(2, Employee::count());
        $this->assertEqualsCanonicalizing(['QA001', 'QA002'], Employee::pluck('employee_code')->all());

        // Defaults applied by the importer, not demanded of the file.
        $this->assertTrue(Employee::where('employee_code', 'QA001')->sole()->is_meal_eligible);
        $this->assertEquals('active', Employee::where('employee_code', 'QA001')->sole()->status);
    }

    public function test_a_file_carrying_those_columns_still_imports_them(): void
    {
        $this->upload('employee_code,name,is_meal_eligible,status
QA001,QA One,false,inactive
');

        $rows = $this->actingAs($this->admin)->get('/company-admin/employees')
            ->getOriginalContent()->getData()['page']['props']['flash']['csvPreview']['valid_rows'];

        $this->actingAs($this->admin)
            ->from('/company-admin/employees')
            ->post('/company-admin/employees/csv-import', ['rows' => $rows])
            ->assertRedirect('/company-admin/employees')
            ->assertSessionHasNoErrors();

        $employee = Employee::sole();
        $this->assertFalse($employee->is_meal_eligible);
        $this->assertEquals('inactive', $employee->status);
    }

    /**
     * The whole response, not just the rows.
     *
     * The importer returns counts per outcome; the controller used to
     * interpolate that array straight into the flash message, so every
     * confirmed import landed its rows and then answered with a 500.
     */
    public function test_confirming_an_import_answers_with_a_redirect_and_a_real_message(): void
    {
        $this->upload("employee_code,name\nQA001,QA One\nQA002,QA Two\n");

        $rows = $this->actingAs($this->admin)->get('/company-admin/employees')
            ->getOriginalContent()->getData()['page']['props']['flash']['csvPreview']['valid_rows'];

        $response = $this->actingAs($this->admin)->from('/company-admin/employees')
            ->post('/company-admin/employees/csv-import', ['rows' => $rows]);

        $response->assertRedirect('/company-admin/employees')->assertSessionHasNoErrors();

        $message = session('message');
        $this->assertIsString($message);
        $this->assertStringContainsString('2 added', $message);
        $this->assertStringNotContainsString('Array', $message);
    }

    public function test_re_importing_the_same_rows_reports_them_as_unchanged(): void
    {
        $this->upload("employee_code,name\nQA001,QA One\n");

        $rows = $this->actingAs($this->admin)->get('/company-admin/employees')
            ->getOriginalContent()->getData()['page']['props']['flash']['csvPreview']['valid_rows'];

        $this->actingAs($this->admin)->from('/company-admin/employees')
            ->post('/company-admin/employees/csv-import', ['rows' => $rows]);

        $this->assertStringContainsString('1 added', session('message'));

        // Same file again: nothing new, nothing changed.
        $this->actingAs($this->admin)->from('/company-admin/employees')
            ->post('/company-admin/employees/csv-import', ['rows' => $rows])
            ->assertRedirect('/company-admin/employees');

        $this->assertStringContainsString('1 unchanged', session('message'));
        $this->assertEquals(1, Employee::count());
    }

    public function test_a_changed_row_reports_as_updated(): void
    {
        $this->upload("employee_code,name\nQA001,QA One\n");
        $rows = $this->actingAs($this->admin)->get('/company-admin/employees')
            ->getOriginalContent()->getData()['page']['props']['flash']['csvPreview']['valid_rows'];
        $this->actingAs($this->admin)->from('/company-admin/employees')
            ->post('/company-admin/employees/csv-import', ['rows' => $rows]);

        $this->upload("employee_code,name\nQA001,QA One Renamed\n");
        $rows = $this->actingAs($this->admin)->get('/company-admin/employees')
            ->getOriginalContent()->getData()['page']['props']['flash']['csvPreview']['valid_rows'];

        $this->actingAs($this->admin)->from('/company-admin/employees')
            ->post('/company-admin/employees/csv-import', ['rows' => $rows])
            ->assertRedirect('/company-admin/employees');

        $this->assertStringContainsString('1 updated', session('message'));
        $this->assertEquals('QA One Renamed', Employee::sole()->name);
    }

    public function test_an_empty_row_list_is_refused_rather_than_reported_as_success(): void
    {
        $this->actingAs($this->admin)->from('/company-admin/employees')
            ->post('/company-admin/employees/csv-import', ['rows' => []])
            ->assertSessionHasErrors('rows');

        $this->assertEquals(0, Employee::count());
    }

    public function test_the_preview_is_flash_data_and_clears_on_the_next_load(): void
    {
        $this->upload("employee_code,name\nQA001,QA One\n");

        $first = $this->actingAs($this->admin)->get('/company-admin/employees')
            ->getOriginalContent()->getData()['page']['props'];
        $this->assertNotNull($first['flash']['csvPreview']);

        $second = $this->actingAs($this->admin)->get('/company-admin/employees')
            ->getOriginalContent()->getData()['page']['props'];
        $this->assertNull($second['flash']['csvPreview']);
    }

    /**
     * A Vue-level guard: the component and the shared key have to agree, and a
     * rename on either side would otherwise go unnoticed until someone opened
     * the modal.
     */
    public function test_the_modal_reads_the_key_the_server_shares(): void
    {
        $modal = file_get_contents(resource_path('js/Pages/CompanyAdmin/Employees/CsvImportModal.vue'));

        $this->assertStringContainsString('page.props.flash?.csvPreview', $modal);

        $shared = $this->actingAs($this->admin)->get('/company-admin/employees')
            ->getOriginalContent()->getData()['page']['props'];

        $this->assertArrayHasKey('csvPreview', $shared['flash']);
    }
}
