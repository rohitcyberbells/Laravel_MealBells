<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two switches that turn attendance reading on, and the credential it needs.
 *
 * Both were previously settable only through tinker, which means the feature was
 * not really available to the people it is for. The key gets the same treatment
 * as every other HRMS credential: write-only, encrypted, and never returned to
 * the browser once stored.
 */
class AttendanceSwitchesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->admin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);
    }

    protected function actor(): User
    {
        return User::findOrFail($this->admin->id);
    }

    /** @return array<string, mixed> */
    protected function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'cutoff_time' => '11:00',
            'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true,
            'attendance_absence_enabled' => false,
            'meal_days' => [1, 2, 3, 4, 5],
        ], $overrides);
    }

    protected function setting(): CompanySetting
    {
        return CompanySetting::where('company_id', $this->company->id)->firstOrFail();
    }

    protected function connection(bool $withBaseUrl = true): CompanyHrmsConnection
    {
        return CompanyHrmsConnection::create(array_filter([
            'company_id' => $this->company->id,
            'pull_adapter' => 'cyberpulse',
            'pull_base_url' => $withBaseUrl ? 'https://hrms.example.test' : null,
        ]));
    }

    // ------------------------------------------------------- the company switch

    public function test_the_settings_screen_reports_the_flag(): void
    {
        $props = $this->actingAs($this->actor())->get('/company-admin/settings')
            ->getOriginalContent()->getData()['page']['props'];

        $this->assertFalse($props['attendance_absence_enabled']);
    }

    public function test_hr_can_turn_attendance_reading_on_and_off(): void
    {
        $this->actingAs($this->actor())
            ->put('/company-admin/settings', $this->settingsPayload(['attendance_absence_enabled' => true]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->setting()->attendance_absence_enabled);

        $this->actingAs($this->actor())
            ->put('/company-admin/settings', $this->settingsPayload(['attendance_absence_enabled' => false]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->setting()->attendance_absence_enabled);
    }

    public function test_saving_other_settings_does_not_silently_turn_it_on(): void
    {
        $this->actingAs($this->actor())
            ->put('/company-admin/settings', $this->settingsPayload(['cutoff_time' => '10:30']))
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->setting()->attendance_absence_enabled);
        $this->assertSame('10:30:00', $this->setting()->cutoff_time);
    }

    public function test_the_screen_says_the_flag_changes_no_count(): void
    {
        $page = file_get_contents(resource_path('js/Pages/CompanyAdmin/Settings/Index.vue'));

        $this->assertStringContainsString('attendance_absence_enabled', $page);
        $this->assertStringContainsString('does not change any', $page);
    }

    // ---------------------------------------------------------- the API key

    public function test_the_hrms_screen_reports_whether_a_key_exists_and_never_the_key(): void
    {
        $this->connection()->forceFill(['attendance_api_key' => 'super-secret-attendance-key'])->save();

        $response = $this->actingAs($this->actor())->get('/company-admin/hrms');
        $props = $response->getOriginalContent()->getData()['page']['props'];

        $this->assertTrue($props['attendance']['has_key']);

        // The whole rendered page, not only the prop we happen to look at.
        $this->assertStringNotContainsString('super-secret-attendance-key', $response->getContent());
        $this->assertStringNotContainsString('super-secret-attendance-key', json_encode($props));
    }

    public function test_hr_can_save_a_key(): void
    {
        $this->connection();

        $this->actingAs($this->actor())
            ->post('/company-admin/hrms/attendance-key', ['attendance_api_key' => 'a-perfectly-good-key-123'])
            ->assertSessionHasNoErrors();

        $connection = CompanyHrmsConnection::where('company_id', $this->company->id)->firstOrFail();

        $this->assertSame('a-perfectly-good-key-123', $connection->attendance_api_key);
    }

    /**
     * Encrypted at rest like every other HRMS credential: a leaked dump must not
     * hand over the ability to read a company's attendance.
     */
    public function test_the_key_is_encrypted_in_the_database(): void
    {
        $this->connection();

        $this->actingAs($this->actor())
            ->post('/company-admin/hrms/attendance-key', ['attendance_api_key' => 'a-perfectly-good-key-123']);

        $raw = DB::table('company_hrms_connections')
            ->where('company_id', $this->company->id)
            ->value('attendance_api_key');

        $this->assertNotSame('a-perfectly-good-key-123', $raw);
        $this->assertStringNotContainsString('a-perfectly-good-key-123', (string) $raw);
    }

    /**
     * The field is write-only, so somebody opening the form and saving it
     * without retyping a key they cannot see must not clear it.
     */
    public function test_saving_an_empty_key_keeps_the_stored_one(): void
    {
        $this->connection()->forceFill(['attendance_api_key' => 'the-original-key-0001'])->save();

        $this->actingAs($this->actor())
            ->post('/company-admin/hrms/attendance-key', ['attendance_api_key' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'the-original-key-0001',
            CompanyHrmsConnection::where('company_id', $this->company->id)->firstOrFail()->attendance_api_key,
        );
    }

    public function test_a_key_can_be_replaced(): void
    {
        $this->connection()->forceFill(['attendance_api_key' => 'the-original-key-0001'])->save();

        $this->actingAs($this->actor())
            ->post('/company-admin/hrms/attendance-key', ['attendance_api_key' => 'the-replacement-key-02']);

        $this->assertSame(
            'the-replacement-key-02',
            CompanyHrmsConnection::where('company_id', $this->company->id)->firstOrFail()->attendance_api_key,
        );
    }

    public function test_a_key_can_be_removed_which_stops_attendance_being_read(): void
    {
        $this->connection()->forceFill(['attendance_api_key' => 'the-original-key-0001'])->save();

        $this->actingAs($this->actor())
            ->delete('/company-admin/hrms/attendance-key')
            ->assertSessionHasNoErrors();

        $connection = CompanyHrmsConnection::where('company_id', $this->company->id)->firstOrFail();

        $this->assertNull($connection->attendance_api_key);
        $this->assertFalse($connection->hasAttendanceCredentials());
    }

    /**
     * A machine key, not a passphrase - so no letters-and-digits rule, but it
     * has to be long enough to be a real key rather than a typo.
     */
    public function test_a_short_key_is_refused(): void
    {
        $this->connection();

        $this->actingAs($this->actor())
            ->post('/company-admin/hrms/attendance-key', ['attendance_api_key' => 'tooshort'])
            ->assertSessionHasErrors('attendance_api_key');

        $this->assertNull(
            CompanyHrmsConnection::where('company_id', $this->company->id)->firstOrFail()->attendance_api_key,
        );
    }

    /**
     * The attendance feed uses the pull connection's base URL, so a key with
     * nowhere to point is refused rather than stored and silently useless.
     */
    public function test_a_key_is_refused_until_the_connection_has_a_base_url(): void
    {
        $this->actingAs($this->actor())
            ->post('/company-admin/hrms/attendance-key', ['attendance_api_key' => 'a-perfectly-good-key-123'])
            ->assertSessionHasErrors('attendance_api_key');

        $this->assertNull(CompanyHrmsConnection::where('company_id', $this->company->id)->first());
    }

    /**
     * Saving the attendance key must not require, or disturb, the HR system
     * password - which is the reason it is a separate form.
     */
    public function test_saving_a_key_leaves_the_pull_password_alone(): void
    {
        $connection = $this->connection();
        $connection->forceFill(['pull_email' => 'sync@hrms.test', 'pull_password' => 'the-pull-password'])->save();

        $this->actingAs($this->actor())
            ->post('/company-admin/hrms/attendance-key', ['attendance_api_key' => 'a-perfectly-good-key-123'])
            ->assertSessionHasNoErrors();

        $fresh = CompanyHrmsConnection::where('company_id', $this->company->id)->firstOrFail();

        $this->assertSame('the-pull-password', $fresh->pull_password);
        $this->assertSame('a-perfectly-good-key-123', $fresh->attendance_api_key);
    }

    // ----------------------------------------------------------- who may do it

    public function test_an_employee_cannot_save_a_key(): void
    {
        $this->connection();

        $employeeUser = User::create([
            'name' => 'Alice', 'email' => 'alice@acme.test', 'password' => bcrypt('password'),
            'role' => 'employee', 'company_id' => $this->company->id,
        ]);

        Employee::create([
            'company_id' => $this->company->id, 'user_id' => $employeeUser->id,
            'employee_code' => 'ACME001', 'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->actingAs($employeeUser)
            ->post('/company-admin/hrms/attendance-key', ['attendance_api_key' => 'a-perfectly-good-key-123'])
            ->assertForbidden();
    }

    public function test_a_guest_cannot_save_a_key(): void
    {
        $this->post('/company-admin/hrms/attendance-key', ['attendance_api_key' => 'a-perfectly-good-key-123'])
            ->assertRedirect('/login');
    }

    /**
     * One tenant's key must not be written onto another's connection.
     */
    public function test_a_key_lands_on_the_signed_in_admins_own_company(): void
    {
        $this->connection();

        $other = Company::create(['name' => 'Beta Corp', 'code' => 'BETA01']);
        CompanyHrmsConnection::create([
            'company_id' => $other->id, 'pull_adapter' => 'cyberpulse',
            'pull_base_url' => 'https://beta.example.test',
        ]);

        $this->actingAs($this->actor())
            ->post('/company-admin/hrms/attendance-key', ['attendance_api_key' => 'a-perfectly-good-key-123']);

        $this->assertNull(
            CompanyHrmsConnection::where('company_id', $other->id)->firstOrFail()->attendance_api_key,
        );
    }

    // ------------------------------------------------------------- the screen

    public function test_the_screen_says_which_switch_is_missing(): void
    {
        $page = file_get_contents(resource_path('js/Pages/CompanyAdmin/Hrms/Index.vue'));

        $this->assertStringContainsString('Save the HR system connection above first', $page);
        $this->assertStringContainsString('No attendance key stored', $page);
        $this->assertStringContainsString('switched off in', $page);
        // Write-only, and the screen has to say so.
        $this->assertStringContainsString('never shown again', $page);
    }
}
