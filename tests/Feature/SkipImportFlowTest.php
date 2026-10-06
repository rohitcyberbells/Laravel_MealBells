<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The individual preview rules are covered by SkipCsvImportTest. These cover the
 * round trip the modal performs: check a file, then confirm the token it was
 * handed, and the effect that has on the day's count.
 */
class SkipImportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected Employee $alice;

    protected Employee $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $tiffin = TiffinService::create(['name' => 'Annapurna Tiffin']);
        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => false, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $this->company->id, 'tiffin_service_id' => $tiffin->id,
            'is_active' => true, 'assigned_at' => '2026-09-01',
        ]);

        $this->admin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        $this->alice = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'ACME001',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->bob = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'ACME002',
            'name' => 'Bob', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    protected function upload(string $csv): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('leave.csv', $csv);
    }

    protected function previewCsv(string $csv, string $type = 'leave')
    {
        return $this->actingAs($this->admin)->postJson('/company-admin/skip-imports/preview', [
            'file' => $this->upload($csv),
            'type' => $type,
        ]);
    }

    protected function confirmToken(?string $token)
    {
        return $this->actingAs($this->admin)
            ->postJson('/company-admin/skip-imports/confirm', ['token' => $token]);
    }

    public function test_the_full_round_trip_imports_and_lowers_the_days_count(): void
    {
        $before = $this->actingAs($this->admin)->get('/company-admin/daily?date=2026-10-06')
            ->getOriginalContent()->getData()['page']['props']['count']['final_expected_count'];

        $this->assertEquals(2, $before);

        $preview = $this->previewCsv("employee_code,from_date,to_date,reason\nACME001,2026-10-06,,Medical\n");

        $preview->assertStatus(200)->assertJsonPath('summary.will_create', 1);

        // Nothing is recorded by the check itself.
        $this->assertEquals(0, Skip::count());

        $this->confirmToken($preview->json('token'))
            ->assertStatus(200)
            ->assertJsonPath('outcomes.created', 1);

        $this->assertEquals(1, Skip::count());
        $this->assertEquals('leave', Skip::sole()->source);

        $after = $this->actingAs(User::findOrFail($this->admin->id))
            ->get('/company-admin/daily?date=2026-10-06')
            ->getOriginalContent()->getData()['page']['props']['count']['final_expected_count'];

        $this->assertEquals(1, $after);
    }

    public function test_a_token_can_only_be_confirmed_once(): void
    {
        $preview = $this->previewCsv("employee_code,from_date\nACME001,2026-10-06\n");
        $token = $preview->json('token');

        $this->confirmToken($token)->assertStatus(200);

        // The token is consumed, so a double submit cannot import twice.
        $this->confirmToken($token)->assertStatus(404);

        $this->assertEquals(1, Skip::count());
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->confirmToken('never-issued')->assertStatus(404);

        $this->assertEquals(0, Skip::count());
    }

    public function test_the_preview_reports_good_and_bad_rows_together(): void
    {
        $csv = "employee_code,from_date,to_date,reason\n"
            ."ACME001,2026-10-06,2026-10-07,Medical\n"   // 2 good days
            ."NOBODY,2026-10-06,,Unknown person\n"        // rejected
            ."ACME002,2026-10-10,,Weekend only\n";        // non-meal day

        $preview = $this->previewCsv($csv);

        $preview->assertStatus(200)
            ->assertJsonPath('summary.total_rows', 3)
            ->assertJsonPath('summary.will_create', 2)
            ->assertJsonPath('summary.rejected', 1)
            ->assertJsonPath('summary.non_meal_days', 1);

        // Each problem says which row and which column, which is what the modal
        // lists for the admin.
        $this->assertEquals('employee_code', $preview->json('errors.0.field'));
        $this->assertEquals(3, $preview->json('errors.0.row'));

        $this->confirmToken($preview->json('token'))->assertJsonPath('outcomes.created', 2);

        $this->assertEquals(2, Skip::count());
    }

    public function test_a_wfh_file_is_refused_while_the_setting_is_off(): void
    {
        $preview = $this->previewCsv("employee_code,from_date\nACME001,2026-10-06\n", 'wfh');

        $preview->assertStatus(200)
            ->assertJsonPath('summary.will_create', 0)
            ->assertJsonPath('errors.0.field', 'type');

        $this->confirmToken($preview->json('token'))->assertJsonPath('outcomes.created', 0);
        $this->assertEquals(0, Skip::count());
    }

    public function test_the_same_wfh_file_imports_once_the_setting_is_on(): void
    {
        CompanySetting::where('company_id', $this->company->id)->update(['wfh_auto_skip' => true]);

        $preview = $this->previewCsv("employee_code,from_date\nACME001,2026-10-06\n", 'wfh');

        $preview->assertStatus(200)->assertJsonPath('summary.will_create', 1);
        $this->confirmToken($preview->json('token'))->assertJsonPath('outcomes.created', 1);

        $this->assertEquals('wfh', Skip::sole()->source);
    }

    public function test_a_missing_file_or_type_is_a_validation_error(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/company-admin/skip-imports/preview', ['type' => 'leave'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->actingAs($this->admin)
            ->postJson('/company-admin/skip-imports/preview', ['file' => $this->upload("employee_code\nACME001\n")])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_the_page_hosting_the_modal_still_loads(): void
    {
        $this->actingAs($this->admin)->get('/company-admin/employees')->assertStatus(200);
    }
}
