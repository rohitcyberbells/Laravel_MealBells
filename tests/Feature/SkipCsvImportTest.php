<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\Skip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SkipCsvImportTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $adminA;

    protected User $adminB;

    protected User $tiffinUser;

    protected Employee $emp1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Company A']);
        $this->companyB = Company::create(['name' => 'Company B']);

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
            'wfh_auto_skip' => false,
            'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->adminA = User::create([
            'name' => 'Admin A',
            'email' => 'admin@comp-a.com',
            'password' => bcrypt('password'),
            'role' => 'company_admin',
            'company_id' => $this->companyA->id,
        ]);

        $this->adminB = User::create([
            'name' => 'Admin B',
            'email' => 'admin@comp-b.com',
            'password' => bcrypt('password'),
            'role' => 'company_admin',
            'company_id' => $this->companyB->id,
        ]);

        $this->tiffinUser = User::create([
            'name' => 'Tiffin User',
            'email' => 'tiffin@vendor.com',
            'password' => bcrypt('password'),
            'role' => 'tiffin_admin',
        ]);

        $this->emp1 = Employee::create([
            'company_id' => $this->companyA->id,
            'employee_code' => 'EMP100',
            'name' => 'John Doe',
            'email' => 'john@comp-a.com',
            'status' => 'active',
            'is_meal_eligible' => true,
        ]);
    }

    public function test_csv_preview_supports_both_date_formats(): void
    {
        $csvContent = "employee_code,from_date,to_date,reason\n".
                      "EMP100,2026-10-12,2026-10-12,Vacation 1\n".
                      "EMP100,13/10/2026,13/10/2026,Vacation 2\n";

        $file = UploadedFile::fake()->createWithContent('skips.csv', $csvContent);

        $response = $this->actingAs($this->adminA)->postJson('/company-admin/skip-imports/preview', [
            'file' => $file,
            'type' => 'leave',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.will_create', 2)
            ->assertJsonPath('summary.rejected', 0);
    }

    public function test_csv_preview_rejects_to_date_earlier_than_from_date(): void
    {
        $csvContent = "employee_code,from_date,to_date\n".
                      "EMP100,2026-10-12,2026-10-05\n";

        $file = UploadedFile::fake()->createWithContent('skips.csv', $csvContent);

        $response = $this->actingAs($this->adminA)->postJson('/company-admin/skip-imports/preview', [
            'file' => $file,
            'type' => 'leave',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.rejected', 1)
            ->assertJsonPath('errors.0.field', 'to_date');
    }

    public function test_csv_preview_rejects_date_range_exceeding_31_days(): void
    {
        $csvContent = "employee_code,from_date,to_date\n".
                      "EMP100,2026-10-01,2026-11-15\n";

        $file = UploadedFile::fake()->createWithContent('skips.csv', $csvContent);

        $response = $this->actingAs($this->adminA)->postJson('/company-admin/skip-imports/preview', [
            'file' => $file,
            'type' => 'leave',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.rejected', 1)
            ->assertJsonPath('errors.0.field', 'to_date');
    }

    public function test_csv_preview_rejects_unknown_employee_code(): void
    {
        $csvContent = "employee_code,from_date\n".
                      "UNKNOWN999,2026-10-12\n";

        $file = UploadedFile::fake()->createWithContent('skips.csv', $csvContent);

        $response = $this->actingAs($this->adminA)->postJson('/company-admin/skip-imports/preview', [
            'file' => $file,
            'type' => 'leave',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.rejected', 1)
            ->assertJsonPath('errors.0.field', 'employee_code');
    }

    public function test_csv_preview_rejects_inactive_employee(): void
    {
        $this->emp1->update(['status' => 'inactive']);

        $csvContent = "employee_code,from_date\n".
                      "EMP100,2026-10-12\n";

        $file = UploadedFile::fake()->createWithContent('skips.csv', $csvContent);

        $response = $this->actingAs($this->adminA)->postJson('/company-admin/skip-imports/preview', [
            'file' => $file,
            'type' => 'leave',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.rejected', 1);
    }

    public function test_csv_preview_rejects_wfh_when_wfh_auto_skip_disabled(): void
    {
        $csvContent = "employee_code,from_date\n".
                      "EMP100,2026-10-12\n";

        $file = UploadedFile::fake()->createWithContent('skips.csv', $csvContent);

        // Admin B belongs to Company B where wfh_auto_skip = false
        $response = $this->actingAs($this->adminB)->postJson('/company-admin/skip-imports/preview', [
            'file' => $file,
            'type' => 'wfh',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.rejected', 1)
            ->assertJsonPath('errors.0.message', 'WFH auto-skip is disabled for your company in settings.');
    }

    public function test_csv_preview_handles_double_upload_as_already_skipped(): void
    {
        Skip::create([
            'company_id' => $this->companyA->id,
            'employee_id' => $this->emp1->id,
            'date' => '2026-10-12',
            'source' => 'leave',
        ]);

        $csvContent = "employee_code,from_date\n".
                      "EMP100,2026-10-12\n";

        $file = UploadedFile::fake()->createWithContent('skips.csv', $csvContent);

        $response = $this->actingAs($this->adminA)->postJson('/company-admin/skip-imports/preview', [
            'file' => $file,
            'type' => 'leave',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.already_skipped', 1);
    }

    public function test_csv_preview_handles_cancelled_skip_as_blocked_cancelled(): void
    {
        Skip::create([
            'company_id' => $this->companyA->id,
            'employee_id' => $this->emp1->id,
            'date' => '2026-10-12',
            'source' => 'leave',
            'cancelled_at' => now(),
        ]);

        $csvContent = "employee_code,from_date\n".
                      "EMP100,2026-10-12\n";

        $file = UploadedFile::fake()->createWithContent('skips.csv', $csvContent);

        $response = $this->actingAs($this->adminA)->postJson('/company-admin/skip-imports/preview', [
            'file' => $file,
            'type' => 'leave',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.blocked_cancelled', 1);
    }

    public function test_csv_preview_counts_non_meal_days_separately(): void
    {
        // 2026-10-10 is Saturday, 2026-10-11 is Sunday, 2026-10-12 is Monday
        $csvContent = "employee_code,from_date,to_date\n".
                      "EMP100,2026-10-10,2026-10-12\n";

        $file = UploadedFile::fake()->createWithContent('skips.csv', $csvContent);

        $response = $this->actingAs($this->adminA)->postJson('/company-admin/skip-imports/preview', [
            'file' => $file,
            'type' => 'leave',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.non_meal_days', 2)
            ->assertJsonPath('summary.will_create', 1);
    }

    public function test_preview_does_not_modify_database(): void
    {
        $csvContent = "employee_code,from_date\n".
                      "EMP100,2026-10-12\n";

        $file = UploadedFile::fake()->createWithContent('skips.csv', $csvContent);

        $this->actingAs($this->adminA)->postJson('/company-admin/skip-imports/preview', [
            'file' => $file,
            'type' => 'leave',
        ]);

        $this->assertDatabaseCount('skips', 0);
    }

    public function test_confirm_persists_valid_skips_and_returns_outcomes(): void
    {
        $csvContent = "employee_code,from_date\n".
                      "EMP100,2026-10-12\n";

        $file = UploadedFile::fake()->createWithContent('skips.csv', $csvContent);

        $previewResponse = $this->actingAs($this->adminA)->postJson('/company-admin/skip-imports/preview', [
            'file' => $file,
            'type' => 'leave',
        ]);

        $token = $previewResponse->json('token');

        $confirmResponse = $this->actingAs($this->adminA)->postJson('/company-admin/skip-imports/confirm', [
            'token' => $token,
        ]);

        $confirmResponse->assertStatus(200)
            ->assertJsonPath('outcomes.created', 1);

        $this->assertDatabaseHas('skips', [
            'company_id' => $this->companyA->id,
            'employee_id' => $this->emp1->id,
            'date' => '2026-10-12',
            'source' => 'leave',
        ]);
    }

    public function test_confirm_with_other_company_token_returns_404(): void
    {
        $csvContent = "employee_code,from_date\n".
                      "EMP100,2026-10-12\n";

        $file = UploadedFile::fake()->createWithContent('skips.csv', $csvContent);

        $previewResponse = $this->actingAs($this->adminA)->postJson('/company-admin/skip-imports/preview', [
            'file' => $file,
            'type' => 'leave',
        ]);

        $token = $previewResponse->json('token');

        // Admin B (Company B) attempts to confirm Company A's token -> 404
        $confirmResponse = $this->actingAs($this->adminB)->postJson('/company-admin/skip-imports/confirm', [
            'token' => $token,
        ]);

        $confirmResponse->assertStatus(404);
    }

    public function test_csv_preview_handles_utf8_bom_in_headers(): void
    {
        $bomHeader = "\xEF\xBB\xBFemployee_code,from_date,to_date\n".
                     "EMP100,2026-10-12,2026-10-12\n";

        $file = UploadedFile::fake()->createWithContent('skips_bom.csv', $bomHeader);

        $response = $this->actingAs($this->adminA)->postJson('/company-admin/skip-imports/preview', [
            'file' => $file,
            'type' => 'leave',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.will_create', 1);
    }

    public function test_tiffin_admin_cannot_access_skip_import_routes(): void
    {
        $file = UploadedFile::fake()->create('skips.csv');

        $response1 = $this->actingAs($this->tiffinUser)->postJson('/company-admin/skip-imports/preview', [
            'file' => $file,
            'type' => 'leave',
        ]);

        $response1->assertStatus(403);

        $response2 = $this->actingAs($this->tiffinUser)->postJson('/company-admin/skip-imports/confirm', [
            'token' => 'dummy_token',
        ]);

        $response2->assertStatus(403);
    }
}
