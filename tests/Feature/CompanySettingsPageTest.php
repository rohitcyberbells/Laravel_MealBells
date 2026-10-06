<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected User $secondAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        $this->admin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        $this->secondAdmin = User::create([
            'name' => 'Acme HR Backup', 'email' => 'hr.backup@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);
    }

    /** @return array<string, mixed> */
    protected function props(): array
    {
        $response = $this->actingAs($this->admin)->get('/company-admin/settings');
        $response->assertStatus(200);

        return $response->getOriginalContent()->getData()['page']['props'];
    }

    protected function setting(): CompanySetting
    {
        return CompanySetting::where('company_id', $this->company->id)->sole();
    }

    /** @return array<string, mixed> */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'cutoff_time' => '10:30',
            'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => false,
            'meal_days' => [1, 2, 3, 4, 5, 6],
            'primary_admin_id' => $this->admin->id,
            'backup_admin_id' => $this->secondAdmin->id,
        ], $overrides);
    }

    public function test_the_page_loads_every_setting_it_can_edit(): void
    {
        $props = $this->props();

        $this->assertEquals('11:00:00', $props['cutoff_time']);
        $this->assertEquals('Asia/Kolkata', $props['timezone']);
        $this->assertTrue($props['wfh_auto_skip']);
        $this->assertEquals([1, 2, 3, 4, 5], $props['meal_days']);
        $this->assertCount(2, $props['company_admins']);
    }

    public function test_it_explains_the_two_settings_that_live_elsewhere(): void
    {
        $props = $this->props();

        // Platform-wide, not per company.
        $this->assertEquals(config('mealbells.advance_limit_days'), $props['advance_limit_days']);

        // Per employee, not per company.
        $this->assertEquals(['manual', 'integrated', 'none'], $props['attendance_sources']);
    }

    public function test_saving_updates_every_field(): void
    {
        $this->actingAs($this->admin)
            ->put('/company-admin/settings', $this->validPayload())
            ->assertSessionHasNoErrors();

        $setting = $this->setting();

        $this->assertEquals('10:30', $setting->cutoff_time);
        $this->assertFalse($setting->wfh_auto_skip);
        $this->assertEquals([1, 2, 3, 4, 5, 6], $setting->meal_days);
        $this->assertEquals($this->admin->id, $setting->primary_admin_id);
        $this->assertEquals($this->secondAdmin->id, $setting->backup_admin_id);
    }

    public function test_saving_reports_success(): void
    {
        $this->actingAs($this->admin)
            ->put('/company-admin/settings', $this->validPayload())
            ->assertSessionHas('message');
    }

    public function test_changing_the_meal_days_changes_which_dates_count(): void
    {
        // Saturday 2026-10-10 is not a meal day to begin with.
        $this->assertFalse($this->isMealDay('2026-10-10'));

        $this->actingAs($this->admin)->put('/company-admin/settings', $this->validPayload())
            ->assertSessionHasNoErrors();

        // Saturday is now included, so the daily screen treats it as one.
        $this->assertTrue($this->isMealDay('2026-10-10'));
    }

    /**
     * actingAs() keeps the same in-memory user across requests, so a relation
     * lazy-loaded by an earlier request stays cached and hides the update. A real
     * request rebuilds the user from the session, so the user is re-resolved here
     * rather than reused.
     */
    protected function isMealDay(string $date): bool
    {
        $admin = User::findOrFail($this->admin->id);

        return $this->actingAs($admin)->get("/company-admin/daily?date={$date}")
            ->getOriginalContent()->getData()['page']['props']['is_meal_day'];
    }

    public function test_an_invalid_timezone_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put('/company-admin/settings', $this->validPayload(['timezone' => 'Mars/Olympus']))
            ->assertSessionHasErrors('timezone');

        $this->assertEquals('Asia/Kolkata', $this->setting()->timezone);
    }

    public function test_an_empty_meal_day_list_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put('/company-admin/settings', $this->validPayload(['meal_days' => []]))
            ->assertSessionHasErrors('meal_days');

        $this->assertEquals([1, 2, 3, 4, 5], $this->setting()->meal_days);
    }

    public function test_a_weekday_outside_one_to_seven_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put('/company-admin/settings', $this->validPayload(['meal_days' => [1, 9]]))
            ->assertSessionHasErrors('meal_days.1');
    }

    public function test_an_admin_from_another_company_cannot_be_set_as_primary(): void
    {
        $other = Company::create(['name' => 'Other Corp', 'code' => 'OTHR01']);
        $outsider = User::create([
            'name' => 'Outsider', 'email' => 'hr@other.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $other->id,
        ]);

        $this->actingAs($this->admin)
            ->put('/company-admin/settings', $this->validPayload(['primary_admin_id' => $outsider->id]))
            ->assertSessionHasErrors('primary_admin_id');

        $this->assertNull($this->setting()->primary_admin_id);
    }

    public function test_adding_an_admin_returns_a_temporary_password_once(): void
    {
        $this->actingAs($this->admin)->post('/company-admin/admins', [
            'name' => 'New Admin', 'email' => 'new@acme.test',
        ])->assertSessionHas('temporary_password');

        $created = User::where('email', 'new@acme.test')->sole();

        $this->assertEquals('company_admin', $created->role);
        $this->assertEquals($this->company->id, $created->company_id);
        $this->assertTrue($created->must_change_password);

        // Shown on the render straight after, then gone.
        $this->assertNotNull($this->props()['temporary_password']);
        $this->assertNull($this->props()['temporary_password']);
    }

    public function test_the_new_admin_appears_in_the_notification_choices(): void
    {
        $this->actingAs($this->admin)->post('/company-admin/admins', [
            'name' => 'New Admin', 'email' => 'new@acme.test',
        ]);

        $this->assertCount(3, $this->props()['company_admins']);
    }

    public function test_an_employee_cannot_reach_or_change_settings(): void
    {
        $employeeUser = User::create([
            'name' => 'Alice', 'email' => 'alice@acme.test', 'password' => bcrypt('password'),
            'role' => 'employee', 'company_id' => $this->company->id,
        ]);

        $this->actingAs($employeeUser)->get('/company-admin/settings')->assertStatus(403);
        $this->actingAs($employeeUser)->put('/company-admin/settings', $this->validPayload())->assertStatus(403);
    }
}
