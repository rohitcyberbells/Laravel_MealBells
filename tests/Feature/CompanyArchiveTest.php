<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealAdjustment;
use App\Models\MealCount;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Archiving a company instead of destroying it.
 *
 * A hard delete cascaded across eleven tables, meal_counts among them - the
 * record of what the kitchen was actually told, and the evidence in any billing
 * dispute. One click, irreversible, with no backup in place.
 */
class CompanyArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Company $other;

    protected User $root;

    protected User $admin;

    protected Employee $employee;

    protected Skip $skip;

    protected MealCount $count;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);
        RateLimiter::clear('login');

        $tiffin = TiffinService::create(['name' => 'Tiffin Co', 'address' => 'A', 'contact_phone' => '9876543210']);

        $this->company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);
        $this->other = Company::create(['name' => 'Beta Corp', 'code' => 'BETA1']);

        foreach ([$this->company, $this->other] as $c) {
            CompanySetting::create([
                'company_id' => $c->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
                'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            ]);
            CompanyTiffinAssignment::create([
                'company_id' => $c->id, 'tiffin_service_id' => $tiffin->id,
                'is_active' => true, 'assigned_at' => now(),
            ]);
        }

        $this->root = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test',
            'password' => bcrypt('password-1'), 'role' => 'super_admin',
        ]);

        $this->admin = User::create([
            'name' => 'Alpha HR', 'email' => 'hr@alpha.test',
            'password' => bcrypt('password-1'), 'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'EMP101',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->skip = Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'date' => '2026-10-08', 'source' => 'hr', 'created_by' => $this->admin->id,
        ]);

        MealAdjustment::create([
            'company_id' => $this->company->id, 'date' => '2026-10-08',
            'quantity' => 2, 'type' => 'guest', 'created_by' => $this->admin->id,
        ]);

        $this->count = MealCount::create([
            'company_id' => $this->company->id, 'tiffin_service_id' => $tiffin->id,
            'date' => '2026-10-06', 'base_eligible_count' => 1, 'skip_count' => 0, 'extra_count' => 0,
            'final_expected_count' => 1, 'breakdown' => [], 'status' => 'confirmed', 'locked_at' => now(),
        ]);
    }

    protected function archive(?string $confirm = null, ?Company $target = null)
    {
        $target ??= $this->company;

        return $this->actingAs($this->root)->from('/super-admin/dashboard')
            ->delete("/super-admin/companies/{$target->id}", [
                'confirm_name' => $confirm ?? $target->name,
            ]);
    }

    protected function signOut(): void
    {
        $this->post('/logout');
        $this->flushSession();
    }

    // -------------------------------------------------------- the confirmation

    /**
     * A confirm() is one keystroke away from archiving the wrong tenant.
     */
    public function test_the_company_name_must_be_typed(): void
    {
        $this->archive('Alpha')->assertSessionHasErrors('confirm_name');
        $this->archive('alpha corp')->assertSessionHasErrors('confirm_name');
        $this->archive('')->assertSessionHasErrors('confirm_name');

        $this->assertNull($this->company->fresh()->deleted_at);
    }

    public function test_the_exact_name_archives_it(): void
    {
        $this->archive()->assertSessionHasNoErrors();

        $this->assertNotNull($this->company->fresh()->deleted_at);
        $this->assertEquals($this->root->id, $this->company->fresh()->deleted_by);
    }

    public function test_the_page_asks_for_the_name_rather_than_confirming(): void
    {
        $page = file_get_contents(resource_path('js/Pages/SuperAdmin/Dashboard.vue'));

        $this->assertStringContainsString('confirm_name', $page);
        $this->assertStringContainsString('to confirm', $page);

        // The one-click delete is gone. The tiffin service on the same screen
        // is archived the same way now - see TiffinServiceArchiveTest.
        $this->assertStringNotContainsString('deleteCompany', $page);
        $this->assertStringNotContainsString('deleteTiffin', $page);
        $this->assertStringContainsString('beginArchive', $page);
    }

    // ----------------------------------------------------------- nothing is lost

    /** @return array<int, array<int, string>> */
    public static function tablesThatMustSurvive(): array
    {
        return [
            ['employees'], ['skips'], ['meal_adjustments'], ['meal_counts'],
            ['company_settings'], ['users'],
        ];
    }

    #[DataProvider('tablesThatMustSurvive')]
    public function test_archiving_destroys_nothing(string $table): void
    {
        $before = \DB::table($table)->count();

        $this->archive()->assertSessionHasNoErrors();

        $this->assertEquals($before, \DB::table($table)->count(), "{$table} lost rows");
    }

    /**
     * The one that matters most: what the kitchen was told.
     */
    public function test_the_locked_count_survives_with_its_figures(): void
    {
        $this->archive();

        $this->assertDatabaseHas('meal_counts', [
            'id' => $this->count->id,
            'final_expected_count' => 1,
            'status' => 'confirmed',
        ]);
    }

    public function test_attribution_on_past_records_survives(): void
    {
        $this->archive();

        $this->assertDatabaseHas('skips', [
            'id' => $this->skip->id,
            'created_by' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    // ------------------------------------------------------------- access stops

    public function test_its_users_cannot_sign_in(): void
    {
        $this->archive();
        $this->signOut();

        $this->post('/login', ['identifier' => 'hr@alpha.test', 'password' => 'password-1'])
            ->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_its_users_are_deactivated_rather_than_deleted(): void
    {
        $this->archive();

        $fresh = $this->admin->fresh();

        $this->assertNotNull($fresh, 'the user row still exists');
        $this->assertFalse($fresh->is_active);
        $this->assertEquals($this->root->id, $fresh->deactivated_by);
    }

    public function test_its_tiffin_assignment_is_stood_down(): void
    {
        $this->archive();

        $this->assertDatabaseHas('company_tiffin_assignments', [
            'company_id' => $this->company->id,
            'is_active' => false,
        ]);
    }

    // ------------------------------------------------------------------ restore

    public function test_it_can_be_restored(): void
    {
        $this->archive();

        $this->actingAs($this->root)->from('/super-admin/dashboard')
            ->post("/super-admin/companies/{$this->company->id}/restore")
            ->assertSessionHasNoErrors();

        $fresh = Company::find($this->company->id);

        $this->assertNotNull($fresh, 'the company is visible again');
        $this->assertNull($fresh->deleted_at);
        $this->assertNull($fresh->deleted_by);
    }

    public function test_restoring_lets_its_users_sign_in_again(): void
    {
        $this->archive();

        $this->actingAs($this->root)->post("/super-admin/companies/{$this->company->id}/restore");
        $this->signOut();

        $this->post('/login', ['identifier' => 'hr@alpha.test', 'password' => 'password-1'])
            ->assertRedirect('/company-admin/dashboard');
    }

    public function test_the_dashboard_lists_archived_companies(): void
    {
        $this->archive();

        $props = $this->actingAs($this->root)->get('/super-admin/dashboard')
            ->getOriginalContent()->getData()['page']['props'];

        $archived = collect($props['archivedCompanies']);

        $this->assertCount(1, $archived);
        $this->assertEquals('Alpha Corp', $archived->first()['name']);
        $this->assertEquals('Root', $archived->first()['deleted_by']);

        // And it is gone from the live list.
        $this->assertNotContains(
            $this->company->id,
            collect($props['companies'])->pluck('id')->all(),
        );
    }

    // ------------------------------------------------------------ other tenants

    public function test_another_company_is_untouched(): void
    {
        $otherAdmin = User::create([
            'name' => 'Beta HR', 'email' => 'hr@beta.test',
            'password' => bcrypt('password-1'), 'role' => 'company_admin', 'company_id' => $this->other->id,
        ]);

        $this->archive();
        $this->signOut();

        $this->assertNull($this->other->fresh()->deleted_at);
        $this->assertTrue($otherAdmin->fresh()->is_active);

        $this->post('/login', ['identifier' => 'hr@beta.test', 'password' => 'password-1'])
            ->assertRedirect('/company-admin/dashboard');
    }

    public function test_only_a_super_admin_can_archive(): void
    {
        $this->actingAs($this->admin)
            ->delete("/super-admin/companies/{$this->company->id}", ['confirm_name' => 'Alpha Corp'])
            ->assertStatus(403);

        $this->assertNull($this->company->fresh()->deleted_at);
    }

    /**
     * A live company's admin, deliberately.
     *
     * This used to use the archived company's own admin, which no longer
     * reaches the role check at all: EnsureAccountIsStillActive signs out an
     * archived company's people on their next request, so the response is a
     * redirect to sign-in rather than a 403. That is the stronger refusal, but
     * it stops this test asserting the thing it is named after - so it asks
     * from a company that is still live.
     */
    public function test_only_a_super_admin_can_restore(): void
    {
        $otherAdmin = User::create([
            'name' => 'Beta HR', 'email' => 'hr@beta.test',
            'password' => bcrypt('password-1'), 'role' => 'company_admin', 'company_id' => $this->other->id,
        ]);

        $this->archive();
        $this->signOut();

        $this->actingAs($otherAdmin)
            ->post("/super-admin/companies/{$this->company->id}/restore")
            ->assertStatus(403);

        $this->assertNotNull($this->company->fresh()->deleted_at);
    }

    /**
     * And the archived company's own admin cannot even get that far.
     */
    public function test_an_archived_companys_admin_is_signed_out_rather_than_forbidden(): void
    {
        $this->archive();

        $this->actingAs(User::findOrFail($this->admin->id))
            ->post("/super-admin/companies/{$this->company->id}/restore")
            ->assertRedirect('/login');

        $this->assertNotNull($this->company->fresh()->deleted_at);
    }

    public function test_restoring_an_unarchived_company_is_a_404(): void
    {
        $this->actingAs($this->root)
            ->post("/super-admin/companies/{$this->other->id}/restore")
            ->assertStatus(404);
    }
}
