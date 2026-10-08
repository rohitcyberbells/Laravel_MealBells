<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\DailyOverrides;
use App\Models\MealCount;
use App\Models\MealCountChange;
use App\Models\MenuItem;
use App\Models\TiffinService;
use App\Models\User;
use App\Models\WeeklyMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Archiving a tiffin service, rather than destroying it.
 *
 * Five tables point at tiffin_services and four of those foreign keys cascade
 * on delete: weekly_menus (and its items), daily_overrides,
 * company_tiffin_assignments and meal_counts.
 *
 * meal_counts is the one that mattered. It is the record of what the kitchen
 * was actually told to cook, and therefore the evidence in any billing dispute
 * with that vendor - so deleting the vendor deleted the proof of what they were
 * asked for, at exactly the moment you need it. Behind a JS confirm(),
 * irreversible.
 */
class TiffinServiceArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected TiffinService $tiffin;

    protected TiffinService $other;

    protected Company $company;

    protected User $root;

    protected User $vendorUser;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);
        RateLimiter::clear('login');

        $this->tiffin = TiffinService::create([
            'name' => 'Royal Tiffin', 'address' => 'Kitchen Rd', 'contact_phone' => '9876543210',
        ]);

        $this->other = TiffinService::create([
            'name' => 'Star Tiffin', 'address' => 'Other Rd', 'contact_phone' => '9876543211',
        ]);

        $this->company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        CompanyTiffinAssignment::create([
            'company_id' => $this->company->id, 'tiffin_service_id' => $this->tiffin->id,
            'is_active' => true, 'assigned_at' => now(),
        ]);

        $this->root = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test',
            'password' => bcrypt('password-1'), 'role' => 'super_admin',
        ]);

        $this->vendorUser = User::create([
            'name' => 'Royal Admin', 'email' => 'vendor@royal.test',
            'password' => bcrypt('password-1'), 'role' => 'tiffin_admin',
            'tiffin_service_id' => $this->tiffin->id,
        ]);

        $menu = WeeklyMenu::create([
            'tiffin_service_id' => $this->tiffin->id,
            'week_start_date' => '2026-10-05', 'status' => 'published',
        ]);

        MenuItem::create([
            'weekly_menu_id' => $menu->id, 'day_of_week' => 'Monday', 'meal_description' => 'Monday thali',
        ]);

        DailyOverrides::create([
            'tiffin_service_id' => $this->tiffin->id,
            'date' => '2026-10-06', 'meal_description' => 'Festival special',
        ]);

        $count = MealCount::create([
            'company_id' => $this->company->id, 'tiffin_service_id' => $this->tiffin->id,
            'date' => '2026-10-06', 'base_eligible_count' => 10, 'skip_count' => 0, 'extra_count' => 0,
            'final_expected_count' => 10, 'breakdown' => [], 'status' => 'confirmed', 'locked_at' => now(),
        ]);

        MealCountChange::create([
            'meal_count_id' => $count->id, 'change_quantity' => 3,
            'reason' => 'Four guests', 'requested_by' => $this->root->id,
        ]);
    }

    protected function archive(?string $confirm = null, ?TiffinService $target = null)
    {
        $target ??= $this->tiffin;

        return $this->actingAs($this->root)->delete(
            "/super-admin/tiffin-services/{$target->id}",
            $confirm === null ? [] : ['confirm_name' => $confirm],
        );
    }

    /**
     * Without this, archive() leaves the super admin signed in and the `guest`
     * middleware bounces /login to /, which reads as a successful sign-in.
     */
    protected function signOut(): void
    {
        $this->post('/logout');
        $this->flushSession();
    }

    // ------------------------------------------------- the confirmation

    public function test_archiving_needs_the_name_typed(): void
    {
        $this->archive()->assertSessionHasErrors('confirm_name');

        $this->assertNotSoftDeleted('tiffin_services', ['id' => $this->tiffin->id]);
    }

    public function test_a_wrong_name_archives_nothing(): void
    {
        $this->archive('Royal')->assertSessionHasErrors('confirm_name');
        $this->archive('royal tiffin')->assertSessionHasErrors('confirm_name');
        $this->archive('Star Tiffin')->assertSessionHasErrors('confirm_name');

        $this->assertNotSoftDeleted('tiffin_services', ['id' => $this->tiffin->id]);
    }

    /**
     * The guard has to be on the server. A disabled button is a convenience,
     * not a control - the request can be sent without ever loading the page.
     */
    public function test_the_check_is_server_side(): void
    {
        $this->archive('Star Tiffin', $this->tiffin)->assertSessionHasErrors('confirm_name');

        $this->assertDatabaseHas('tiffin_services', [
            'id' => $this->tiffin->id, 'deleted_at' => null,
        ]);
    }

    public function test_the_right_name_archives_it(): void
    {
        $this->archive('Royal Tiffin')->assertSessionHasNoErrors();

        $this->assertSoftDeleted('tiffin_services', ['id' => $this->tiffin->id]);
    }

    public function test_surrounding_whitespace_is_forgiven(): void
    {
        $this->archive('  Royal Tiffin  ')->assertSessionHasNoErrors();

        $this->assertSoftDeleted('tiffin_services', ['id' => $this->tiffin->id]);
    }

    public function test_it_records_who_archived_it(): void
    {
        $this->archive('Royal Tiffin');

        $this->assertDatabaseHas('tiffin_services', [
            'id' => $this->tiffin->id, 'deleted_by' => $this->root->id,
        ]);
    }

    // ------------------------------------------------- nothing is lost

    /** @return array<int, array<int, string>> */
    public static function tablesThatMustSurvive(): array
    {
        return [
            ['meal_counts'], ['meal_count_changes'], ['weekly_menus'],
            ['menu_items'], ['daily_overrides'], ['company_tiffin_assignments'], ['users'],
        ];
    }

    /**
     * The whole point. Four of these cascaded on delete before, meal_counts
     * among them.
     */
    #[DataProvider('tablesThatMustSurvive')]
    public function test_no_row_is_destroyed(string $table): void
    {
        $before = DB::table($table)->count();

        $this->assertGreaterThan(0, $before, "the fixture seeded no {$table} to lose");

        $this->archive('Royal Tiffin')->assertSessionHasNoErrors();

        $this->assertSame(
            $before,
            DB::table($table)->count(),
            "{$table} lost rows when the service was archived",
        );
    }

    /**
     * Specifically: the count the vendor was given, and the post-cutoff change
     * on top of it. This is the billing evidence.
     */
    public function test_the_locked_count_and_its_late_change_survive_intact(): void
    {
        $this->archive('Royal Tiffin');

        $count = MealCount::where('tiffin_service_id', $this->tiffin->id)->firstOrFail();

        $this->assertSame(10, $count->final_expected_count);
        $this->assertSame(13, $count->adjusted_total);
        $this->assertNotNull($count->locked_at);
    }

    // ------------------------------------------------- access

    public function test_its_logins_are_deactivated_rather_than_deleted(): void
    {
        $this->archive('Royal Tiffin');

        $this->assertDatabaseHas('users', [
            'id' => $this->vendorUser->id,
            'is_active' => false,
            'deactivated_by' => $this->root->id,
        ]);
    }

    public function test_a_vendor_user_cannot_sign_in_once_archived(): void
    {
        $this->archive('Royal Tiffin');
        $this->signOut();

        $this->post('/login', [
            'identifier' => 'vendor@royal.test',
            'password' => 'password-1',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }

    /**
     * The second lock. Archiving deactivates the logins, so is_active already
     * refuses them - but an account reactivated by hand, or created after the
     * archive, would otherwise sign in and get a preparation screen for a
     * service that no longer exists.
     */
    public function test_reactivating_the_account_does_not_get_it_back_in(): void
    {
        $this->archive('Royal Tiffin');

        $this->vendorUser->update([
            'is_active' => true, 'deactivated_at' => null, 'deactivated_by' => null,
        ]);

        $this->signOut();

        $this->post('/login', [
            'identifier' => 'vendor@royal.test',
            'password' => 'password-1',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_another_services_logins_are_untouched(): void
    {
        $otherUser = User::create([
            'name' => 'Star Admin', 'email' => 'vendor@star.test',
            'password' => bcrypt('password-1'), 'role' => 'tiffin_admin',
            'tiffin_service_id' => $this->other->id,
        ]);

        $this->archive('Royal Tiffin');

        $this->assertDatabaseHas('users', ['id' => $otherUser->id, 'is_active' => true]);

        $this->signOut();

        $this->post('/login', [
            'identifier' => 'vendor@star.test',
            'password' => 'password-1',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($otherUser->fresh());
    }

    public function test_its_assignments_are_stood_down(): void
    {
        $this->archive('Royal Tiffin');

        $this->assertDatabaseHas('company_tiffin_assignments', [
            'company_id' => $this->company->id,
            'tiffin_service_id' => $this->tiffin->id,
            'is_active' => false,
        ]);
    }

    /**
     * Unpairing a company stops its cutoff producing a count at all, which is
     * severe enough that the screen has to say so rather than report a bland
     * success.
     */
    public function test_the_response_warns_how_many_companies_are_now_unpaired(): void
    {
        $this->archive('Royal Tiffin');

        $this->assertStringContainsString(
            '1 company is now unpaired',
            (string) session('message'),
        );
    }

    public function test_no_warning_when_nothing_was_paired(): void
    {
        $this->archive('Star Tiffin', $this->other)->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('unpaired', (string) session('message'));
    }

    // ------------------------------------------------- the listing

    public function test_an_archived_service_leaves_the_live_list(): void
    {
        $this->archive('Royal Tiffin');

        $props = $this->actingAs($this->root)->get('/super-admin/dashboard')
            ->getOriginalContent()->getData()['page']['props'];

        $this->assertNotContains(
            'Royal Tiffin',
            collect($props['tiffinServices'])->pluck('name')->all(),
        );
        $this->assertContains(
            'Star Tiffin',
            collect($props['tiffinServices'])->pluck('name')->all(),
        );
    }

    /**
     * Listed, so an archive is visible and reversible rather than just gone
     * from the page with no way back.
     */
    public function test_an_archived_service_is_listed_separately_with_who_and_when(): void
    {
        $this->archive('Royal Tiffin');

        $props = $this->actingAs($this->root)->get('/super-admin/dashboard')
            ->getOriginalContent()->getData()['page']['props'];

        $archived = collect($props['archivedTiffinServices'])->firstWhere('name', 'Royal Tiffin');

        $this->assertNotNull($archived, 'the archived service is not listed anywhere');
        $this->assertSame('Root', $archived['deleted_by']);
        $this->assertNotNull($archived['deleted_at']);
    }

    /**
     * The pairing history is the one place an archived name must still show:
     * the row for an archived service is exactly the row somebody is looking
     * for, and a soft delete would otherwise render it blank.
     */
    public function test_the_pairing_history_still_names_an_archived_service(): void
    {
        $this->archive('Royal Tiffin');

        $props = $this->actingAs($this->root)->get('/super-admin/dashboard')
            ->getOriginalContent()->getData()['page']['props'];

        $names = collect($props['assignments'])->pluck('tiffin_service.name')->filter()->all();

        $this->assertContains('Royal Tiffin', $names);
    }

    // ------------------------------------------------- restore

    public function test_restore_brings_it_back_and_its_logins_with_it(): void
    {
        $this->archive('Royal Tiffin');

        $this->actingAs($this->root)
            ->post("/super-admin/tiffin-services/{$this->tiffin->id}/restore")
            ->assertSessionHasNoErrors();

        $this->assertNotSoftDeleted('tiffin_services', ['id' => $this->tiffin->id]);
        $this->assertDatabaseHas('tiffin_services', ['id' => $this->tiffin->id, 'deleted_by' => null]);
        $this->assertDatabaseHas('users', ['id' => $this->vendorUser->id, 'is_active' => true]);

        $this->signOut();

        $this->post('/login', [
            'identifier' => 'vendor@royal.test',
            'password' => 'password-1',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($this->vendorUser->fresh());
    }

    /**
     * Deliberately NOT re-activated. The company may have been paired with
     * another vendor meanwhile, and two active assignments are forbidden by a
     * database constraint - so pairing is a decision made again by hand.
     */
    public function test_restore_leaves_the_pairing_to_be_made_again(): void
    {
        $this->archive('Royal Tiffin');

        $this->actingAs($this->root)->post("/super-admin/tiffin-services/{$this->tiffin->id}/restore");

        $this->assertDatabaseHas('company_tiffin_assignments', [
            'company_id' => $this->company->id,
            'tiffin_service_id' => $this->tiffin->id,
            'is_active' => false,
        ]);

        $this->assertStringContainsString('still unpaired', (string) session('message'));
    }

    public function test_restoring_something_that_is_not_archived_is_a_404(): void
    {
        $this->actingAs($this->root)
            ->post("/super-admin/tiffin-services/{$this->other->id}/restore")
            ->assertNotFound();
    }

    // ------------------------------------------------- authorisation

    public function test_only_a_super_admin_can_archive_one(): void
    {
        $vendor = $this->vendorUser;

        $this->actingAs($vendor)
            ->delete("/super-admin/tiffin-services/{$this->tiffin->id}", ['confirm_name' => 'Royal Tiffin'])
            ->assertForbidden();

        $this->assertNotSoftDeleted('tiffin_services', ['id' => $this->tiffin->id]);
    }

    public function test_only_a_super_admin_can_restore_one(): void
    {
        $this->archive('Royal Tiffin');

        $companyAdmin = User::create([
            'name' => 'Alpha HR', 'email' => 'hr@alpha.test',
            'password' => bcrypt('password-1'), 'role' => 'company_admin',
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($companyAdmin)
            ->post("/super-admin/tiffin-services/{$this->tiffin->id}/restore")
            ->assertForbidden();

        $this->assertSoftDeleted('tiffin_services', ['id' => $this->tiffin->id]);
    }

    // ------------------------------------------------- the screen

    public function test_the_screen_asks_for_the_name_instead_of_a_confirm(): void
    {
        $page = file_get_contents(resource_path('js/Pages/SuperAdmin/Dashboard.vue'));

        $this->assertStringContainsString('beginArchiveTiffin', $page);
        $this->assertStringContainsString('archivedTiffinServices', $page);
        $this->assertStringContainsString('restoreTiffin', $page);

        // The one-click hard delete is gone.
        $this->assertStringNotContainsString('deleteTiffin', $page);
        $this->assertStringNotContainsString('cannot be undone', $page);
    }
}
