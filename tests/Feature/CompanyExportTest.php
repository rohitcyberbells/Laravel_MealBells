<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealAdjustment;
use App\Models\MealCount;
use App\Models\MealCountChange;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * A company taking its own data out.
 *
 * The export is the one response that hands over a whole tenant's personal data
 * in a single file, so the two things that matter are that it is complete and
 * that it is strictly one tenant's. Both are asserted against a database that
 * holds a second company with deliberately similar data.
 */
class CompanyExportTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Company $other;

    protected User $admin;

    protected User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);

        $tiffin = TiffinService::create([
            'name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890',
        ]);

        [$this->company, $this->admin] = $this->seedCompany('Alpha Corp', 'ALPHA1', 'Alice Alpha', $tiffin);
        [$this->other, $this->otherAdmin] = $this->seedCompany('Beta Corp', 'BETA01', 'Bob Beta', $tiffin);
    }

    /** @return array{0: Company, 1: User} */
    protected function seedCompany(string $name, string $code, string $employeeName, TiffinService $tiffin): array
    {
        $company = Company::create(['name' => $name, 'code' => $code]);

        CompanySetting::create([
            'company_id' => $company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $company->id, 'tiffin_service_id' => $tiffin->id,
            'is_active' => true, 'assigned_at' => '2026-09-01',
        ]);

        $admin = User::create([
            'name' => "{$name} HR", 'email' => 'hr@'.strtolower($code).'.test',
            'password' => bcrypt('password'), 'role' => 'company_admin', 'company_id' => $company->id,
        ]);

        $employee = Employee::create([
            'company_id' => $company->id, 'employee_code' => $code.'001',
            'name' => $employeeName, 'email' => strtolower(str_replace(' ', '.', $employeeName)).'@test.test',
            'status' => 'active', 'is_meal_eligible' => true, 'external_id' => 'hr-'.strtolower($code),
        ]);

        Skip::create([
            'company_id' => $company->id, 'employee_id' => $employee->id,
            'date' => '2026-10-09', 'source' => 'hr',
            'reason' => "{$employeeName} is away", 'created_by' => $admin->id,
        ]);

        MealAdjustment::create([
            'company_id' => $company->id, 'date' => '2026-10-09',
            'quantity' => 3, 'type' => 'guest', 'reason' => "Guests of {$name}",
            'created_by' => $admin->id,
        ]);

        $count = MealCount::create([
            'company_id' => $company->id, 'tiffin_service_id' => $tiffin->id,
            'date' => '2026-10-06', 'base_eligible_count' => 1, 'skip_count' => 0, 'extra_count' => 0,
            'final_expected_count' => 1, 'breakdown' => [], 'status' => 'confirmed',
            'locked_at' => now(), 'lock_type' => 'auto',
        ]);

        MealCountChange::create([
            'meal_count_id' => $count->id, 'change_quantity' => 2,
            'reason' => 'Late guests', 'requested_by' => $admin->id,
        ]);

        return [$company, $admin];
    }

    /** @return array<string, string> */
    protected function download(?User $as = null): array
    {
        $response = $this->actingAs(User::findOrFail(($as ?? $this->admin)->id))
            ->get('/company-admin/export');

        $response->assertStatus(200);
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));

        $path = tempnam(sys_get_temp_dir(), 'export').'.zip';
        file_put_contents($path, $response->streamedContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'the download is not a readable zip');

        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $files[$name] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        @unlink($path);

        return $files;
    }

    public function test_it_downloads_a_zip_of_four_csvs_and_a_readme(): void
    {
        $files = $this->download();

        $this->assertSame(
            ['README.txt', 'daily_counts.csv', 'employees.csv', 'extra_meals.csv', 'skips.csv'],
            collect(array_keys($files))->sort()->values()->all(),
        );
    }

    public function test_the_employee_file_carries_the_employee(): void
    {
        $employees = $this->download()['employees.csv'];

        $this->assertStringContainsString('employee_code,name,email', $employees);
        $this->assertStringContainsString('ALPHA1001', $employees);
        $this->assertStringContainsString('Alice Alpha', $employees);
        $this->assertStringContainsString('hr-alpha1', $employees);
    }

    public function test_the_skip_file_carries_the_skip_and_who_it_was_for(): void
    {
        $skips = $this->download()['skips.csv'];

        $this->assertStringContainsString('2026-10-09', $skips);
        $this->assertStringContainsString('ALPHA1001', $skips);
        $this->assertStringContainsString('Alice Alpha is away', $skips);
        $this->assertStringContainsString(',hr,', $skips);
    }

    public function test_the_extra_meals_file_carries_the_extras(): void
    {
        $extras = $this->download()['extra_meals.csv'];

        $this->assertStringContainsString('2026-10-09,3,guest,"Guests of Alpha Corp"', $extras);
    }

    /**
     * The figures a billing question is actually about: what the kitchen was
     * told when the day locked, and what it became after a late change. They
     * differ, and both are needed.
     */
    public function test_the_daily_counts_file_carries_both_the_locked_and_adjusted_figures(): void
    {
        $counts = $this->download()['daily_counts.csv'];

        $this->assertStringContainsString('final_expected,adjusted_total', $counts);

        $row = collect(explode("\n", $counts))->first(fn ($line) => str_starts_with($line, '2026-10-06'));

        $this->assertNotNull($row);
        // final_expected 1, adjusted_total 3 after the +2 post-cutoff change.
        $this->assertStringContainsString('1,3,confirmed,auto', $row);
    }

    public function test_the_readme_names_the_company_and_when_it_was_taken(): void
    {
        $readme = $this->download()['README.txt'];

        $this->assertStringContainsString('Alpha Corp (ALPHA1)', $readme);
        $this->assertStringContainsString(now()->toDateString(), $readme);
        $this->assertStringContainsString('hr@alpha1.test', $readme);
        $this->assertStringContainsString('contains personal data', $readme);
    }

    // ------------------------------------------------------------- one tenant

    /**
     * The whole risk of this feature in one test.
     */
    public function test_nothing_of_another_tenant_appears_anywhere_in_the_export(): void
    {
        $everything = implode("\n", $this->download());

        foreach (['Beta Corp', 'BETA01', 'Bob Beta', 'bob.beta@test.test', 'hr-beta01', 'Guests of Beta Corp'] as $theirs) {
            $this->assertStringNotContainsString($theirs, $everything, "the export leaked: {$theirs}");
        }
    }

    public function test_each_admin_gets_only_their_own_company(): void
    {
        $theirs = implode("\n", $this->download($this->otherAdmin));

        $this->assertStringContainsString('Bob Beta', $theirs);
        $this->assertStringNotContainsString('Alice Alpha', $theirs);
    }

    /**
     * There is no company parameter to tamper with - it comes from the signed-in
     * admin. Asserted because a query string that was quietly honoured would be
     * the simplest possible breach of the whole system.
     */
    public function test_a_company_cannot_be_named_in_the_request(): void
    {
        $files = $this->actingAs(User::findOrFail($this->admin->id))
            ->get('/company-admin/export?company_id='.$this->other->id.'&company='.$this->other->code);

        $files->assertStatus(200);

        $this->assertStringNotContainsString('Bob Beta', $files->streamedContent());
    }

    // ------------------------------------------------------------- who may ask

    public function test_an_employee_cannot_export(): void
    {
        $employeeUser = User::create([
            'name' => 'Alice', 'email' => 'alice@alpha1.test', 'password' => bcrypt('password'),
            'role' => 'employee', 'company_id' => $this->company->id,
        ]);

        $this->actingAs($employeeUser)->get('/company-admin/export')->assertStatus(403);
    }

    public function test_a_vendor_cannot_export(): void
    {
        $vendor = User::create([
            'name' => 'Vendor', 'email' => 'vendor@royal.test', 'password' => bcrypt('password'),
            'role' => 'tiffin_admin', 'tiffin_service_id' => TiffinService::first()->id,
        ]);

        $this->actingAs($vendor)->get('/company-admin/export')->assertStatus(403);
    }

    public function test_a_guest_cannot_export(): void
    {
        $this->get('/company-admin/export')->assertRedirect('/login');
    }

    /**
     * A file holding every employee's name and address must not sit in storage
     * after it has been handed over.
     */
    public function test_the_file_is_not_left_behind(): void
    {
        $this->download();

        $this->assertSame(
            [],
            glob(storage_path('app/exports/*.zip')) ?: [],
            'the export was left in storage after being downloaded',
        );
    }

    public function test_the_screen_offers_the_download(): void
    {
        $page = file_get_contents(resource_path('js/Pages/CompanyAdmin/Settings/Index.vue'));

        $this->assertStringContainsString('/company-admin/export', $page);
    }
}
