<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The credentials were flashed and no page read them, so a generated password
 * was never shown to anyone.
 */
class EmployeeLoginCredentialsPanelTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected Employee $withEmail;

    protected Employee $withoutEmail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->admin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        $this->withEmail = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'ACME001', 'email' => 'acme001@demo.test',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->withoutEmail = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'ACME002', 'email' => null,
            'name' => 'Bob', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    protected function createLogins(array $ids)
    {
        return $this->actingAs($this->admin)
            ->from('/company-admin/employees')
            ->post('/company-admin/employees/logins', ['employee_ids' => $ids]);
    }

    /** @return array<string, mixed> */
    protected function employeesProps(): array
    {
        return $this->actingAs($this->admin)->get('/company-admin/employees')
            ->getOriginalContent()->getData()['page']['props'];
    }

    public function test_the_generated_credentials_reach_the_page(): void
    {
        $this->createLogins([$this->withEmail->id, $this->withoutEmail->id])
            ->assertRedirect('/company-admin/employees');

        $this->actingAs($this->admin)->get('/company-admin/employees')
            ->assertInertia(fn (Assert $page) => $page
                ->component('CompanyAdmin/Employees/Index')
                // Exactly the path the panel reads.
                ->has('flash.credentials', 2)
                ->etc()
            );
    }

    public function test_each_row_carries_what_the_panel_shows(): void
    {
        $this->createLogins([$this->withEmail->id, $this->withoutEmail->id]);

        $credentials = collect($this->employeesProps()['flash']['credentials'])->keyBy('employee_code');

        $alice = $credentials['ACME001'];
        $this->assertEquals('Alice', $alice['name']);
        $this->assertNotEmpty($alice['temporary_password']);
        $this->assertEquals('acme001@demo.test', $alice['email']);
        $this->assertTrue($alice['can_login_with_email']);

        // No address, so the panel shows the company code and employee code.
        $bob = $credentials['ACME002'];
        $this->assertNull($bob['email']);
        $this->assertFalse($bob['can_login_with_email']);
        $this->assertEquals('ACME01', $bob['company_code']);
    }

    public function test_the_panel_is_gone_on_a_reload(): void
    {
        $this->createLogins([$this->withEmail->id]);

        $this->assertNotNull($this->employeesProps()['flash']['credentials']);

        // Flash data, so the second render no longer carries it.
        $this->assertNull($this->employeesProps()['flash']['credentials']);
    }

    public function test_the_password_is_never_stored_in_plain_text(): void
    {
        $this->createLogins([$this->withEmail->id]);

        $password = $this->employeesProps()['flash']['credentials'][0]['temporary_password'];

        $this->assertNotEmpty($password);

        $stored = DB::table('users')->where('login_code', 'ACME001')->value('password');
        $this->assertNotEquals($password, $stored);
        $this->assertTrue(password_verify($password, $stored));

        // And nowhere else in the row either.
        $row = json_encode((array) DB::table('users')->where('login_code', 'ACME001')->first());
        $this->assertStringNotContainsString($password, $row);
    }

    public function test_a_password_reset_shows_its_credential_too(): void
    {
        $this->createLogins([$this->withEmail->id]);
        $this->employeesProps(); // consume the first flash

        $this->actingAs($this->admin)
            ->from('/company-admin/employees')
            ->post("/company-admin/employees/{$this->withEmail->id}/reset-password")
            ->assertRedirect('/company-admin/employees');

        $credentials = $this->employeesProps()['flash']['credentials'];

        $this->assertCount(1, $credentials);
        $this->assertEquals('ACME001', $credentials[0]['employee_code']);
        $this->assertNotEmpty($credentials[0]['temporary_password']);
    }

    public function test_the_employee_rows_say_who_already_has_a_login(): void
    {
        $this->createLogins([$this->withEmail->id]);
        $this->employeesProps();

        $rows = collect($this->employeesProps()['employees']['data'])->keyBy('employee_code');

        // The checkbox is only offered where user_id is null.
        $this->assertNotNull($rows['ACME001']['user_id']);
        $this->assertNull($rows['ACME002']['user_id']);
    }

    /**
     * The component and the shared key have to agree; a rename on either side
     * would otherwise only surface when someone generated a login.
     */
    public function test_the_page_reads_the_key_the_server_shares(): void
    {
        $index = file_get_contents(resource_path('js/Pages/CompanyAdmin/Employees/Index.vue'));

        $this->assertStringContainsString('page.props.flash?.credentials', $index);
        $this->assertStringContainsString('<LoginCredentialsPanel', $index);

        $this->assertArrayHasKey('credentials', $this->employeesProps()['flash']);
    }
}
