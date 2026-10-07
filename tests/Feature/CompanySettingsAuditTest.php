<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who last changed a company's meal settings.
 *
 * Every other table that affects a count records its actor. This row did not -
 * and it is the highest-leverage record there is: moving the cutoff or dropping
 * a meal day changes every future count for the whole company.
 */
class CompanySettingsAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);

        $this->company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->admin = User::create([
            'name' => 'Priya Sharma', 'email' => 'hr@alpha.test', 'password' => bcrypt('password-1'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        $this->otherAdmin = User::create([
            'name' => 'Rohan Mehta', 'email' => 'hr2@alpha.test', 'password' => bcrypt('password-1'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);
    }

    protected function save(User $as, array $overrides = [])
    {
        return $this->actingAs($as)->from('/company-admin/settings')
            ->put('/company-admin/settings', array_merge([
                'cutoff_time' => '11:00',
                'timezone' => 'Asia/Kolkata',
                'wfh_auto_skip' => true,
                'meal_days' => [1, 2, 3, 4, 5],
                'primary_admin_id' => null,
                'backup_admin_id' => null,
            ], $overrides));
    }

    protected function setting(): CompanySetting
    {
        return CompanySetting::where('company_id', $this->company->id)->sole();
    }

    public function test_the_row_starts_with_no_actor(): void
    {
        $this->assertNull($this->setting()->updated_by);
        $this->assertNull($this->setting()->settings_changed_at);
    }

    public function test_changing_the_cutoff_records_who_did_it(): void
    {
        $this->save($this->admin, ['cutoff_time' => '09:30'])->assertSessionHasNoErrors();

        $setting = $this->setting();

        $this->assertEquals($this->admin->id, $setting->updated_by);
        $this->assertNotNull($setting->settings_changed_at);
        $this->assertEquals('Priya Sharma', $setting->updatedBy->name);
    }

    public function test_a_later_change_records_the_later_person(): void
    {
        $this->save($this->admin, ['cutoff_time' => '09:30']);
        $this->save($this->otherAdmin, ['meal_days' => [1, 2, 3]]);

        $this->assertEquals($this->otherAdmin->id, $this->setting()->updated_by);
        $this->assertEquals('Rohan Mehta', $this->setting()->updatedBy->name);
    }

    /** @return array<int, array<int, mixed>> */
    public static function settingsThatChangeEveryFutureCount(): array
    {
        return [
            [['cutoff_time' => '08:00']],
            [['meal_days' => [1, 2, 3]]],
            [['timezone' => 'Asia/Dubai']],
            [['wfh_auto_skip' => false]],
        ];
    }

    #[DataProvider('settingsThatChangeEveryFutureCount')]
    public function test_each_high_impact_setting_is_attributed(array $change): void
    {
        $this->save($this->admin, $change)->assertSessionHasNoErrors();

        $this->assertEquals($this->admin->id, $this->setting()->updated_by);
    }

    /**
     * Kept separate from updated_at, which also moves when nothing a person did
     * was involved.
     */
    public function test_the_timestamp_is_its_own_column(): void
    {
        $this->save($this->admin, ['cutoff_time' => '09:30']);
        $changedAt = $this->setting()->settings_changed_at;

        $this->assertNotNull($changedAt);

        // A write that is not a person changing settings.
        $this->setting()->forceFill(['primary_admin_id' => $this->admin->id])->save();

        $this->assertEquals(
            $changedAt->toDateTimeString(),
            $this->setting()->settings_changed_at->toDateTimeString(),
        );
    }

    public function test_the_settings_page_shows_who_last_changed_them(): void
    {
        $this->save($this->admin, ['cutoff_time' => '09:30']);

        $props = $this->actingAs($this->admin)->get('/company-admin/settings')
            ->getOriginalContent()->getData()['page']['props'];

        $this->assertNotNull($props['last_changed']);
        $this->assertEquals('Priya Sharma', $props['last_changed']['by']);
        $this->assertNotEmpty($props['last_changed']['at']);

        $page = file_get_contents(resource_path('js/Pages/CompanyAdmin/Settings/Index.vue'));
        $this->assertStringContainsString('last_changed', $page);
    }

    public function test_a_page_with_no_recorded_change_shows_nothing(): void
    {
        $props = $this->actingAs($this->admin)->get('/company-admin/settings')
            ->getOriginalContent()->getData()['page']['props'];

        $this->assertNull($props['last_changed']);
    }

    /**
     * Deleting the user must not take the settings row with it - the company
     * still has a cutoff.
     */
    public function test_deleting_the_actor_leaves_the_settings_intact(): void
    {
        $this->save($this->admin, ['cutoff_time' => '09:30']);

        $this->admin->delete();

        $setting = $this->setting();

        $this->assertNull($setting->updated_by);
        // Stored as the form sent it; the seeder writes '11:00:00'. Both are
        // parsed the same way, so the column holds either shape.
        $this->assertStringStartsWith('09:30', $setting->cutoff_time);
        $this->assertNotNull($setting->settings_changed_at, 'when it changed is still known');
    }
}
