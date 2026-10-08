<?php

namespace Tests\Feature;

use App\Actions\Employee\AnonymiseEmployee;
use App\Actions\Meal\CalculateExpectedMeals;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealCount;
use App\Models\RecurringSkip;
use App\Models\Skip;
use App\Models\TiffinService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * "Remove my data", without breaking the arithmetic.
 *
 * A data-subject request pulls two ways at once: the person's identity has to
 * go, and the company's records have to stay reproducible. Deleting the
 * employee row would do the first and destroy the second - every skip they ever
 * had cascades, so the count the kitchen was given for a past day could no
 * longer be derived from the data behind it, and that count is the evidence in
 * a billing dispute.
 *
 * So the row stays and stops identifying anyone. These assert both halves.
 */
class EmployeeAnonymisationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Company $other;

    protected User $admin;

    protected User $otherAdmin;

    protected Employee $employee;

    protected Employee $theirEmployee;

    protected User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);
        RateLimiter::clear('login');

        $tiffin = TiffinService::create([
            'name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890',
        ]);

        $this->company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);
        $this->other = Company::create(['name' => 'Beta Corp', 'code' => 'BETA01']);

        foreach ([$this->company, $this->other] as $company) {
            CompanySetting::create([
                'company_id' => $company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
                'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            ]);
            CompanyTiffinAssignment::create([
                'company_id' => $company->id, 'tiffin_service_id' => $tiffin->id,
                'is_active' => true, 'assigned_at' => '2026-09-01',
            ]);
        }

        $this->admin = User::create([
            'name' => 'Alpha HR', 'email' => 'hr@alpha1.test', 'password' => bcrypt('password-1'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        $this->otherAdmin = User::create([
            'name' => 'Beta HR', 'email' => 'hr@beta01.test', 'password' => bcrypt('password-1'),
            'role' => 'company_admin', 'company_id' => $this->other->id,
        ]);

        $this->employeeUser = User::create([
            'name' => 'Alice Sharma', 'email' => 'alice.sharma@alpha1.test',
            'password' => bcrypt('password-1'), 'role' => 'employee',
            'company_id' => $this->company->id, 'login_code' => 'ALPHA1001',
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'user_id' => $this->employeeUser->id,
            'employee_code' => 'ALPHA1001', 'name' => 'Alice Sharma',
            'email' => 'alice.sharma@alpha1.test', 'external_id' => 'hr-66e0bb01',
            'status' => 'active', 'is_meal_eligible' => true,
        ]);

        // A colleague, so the company still has someone eligible afterwards and
        // the count arithmetic is not trivially zero.
        Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'ALPHA1002',
            'name' => 'Bob Kumar', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->theirEmployee = Employee::create([
            'company_id' => $this->other->id, 'employee_code' => 'BETA01001',
            'name' => 'Carol Beta', 'email' => 'carol@beta01.test',
            'status' => 'active', 'is_meal_eligible' => true,
        ]);

        Skip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'date' => '2026-10-02', 'source' => 'hr',
            'reason' => 'Dental surgery follow-up', 'created_by' => $this->admin->id,
        ]);

        RecurringSkip::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'weekday' => 5, 'starts_on' => '2026-09-01', 'active' => true,
        ]);

        MealCount::create([
            'company_id' => $this->company->id, 'tiffin_service_id' => $tiffin->id,
            'date' => '2026-10-02', 'base_eligible_count' => 2, 'skip_count' => 1, 'extra_count' => 0,
            'final_expected_count' => 1, 'breakdown' => [], 'status' => 'confirmed',
            'locked_at' => now(), 'lock_type' => 'auto',
        ]);
    }

    protected function anonymise(?string $confirm = null, ?Employee $target = null, ?User $as = null)
    {
        $target ??= $this->employee;

        return $this->actingAs(User::findOrFail(($as ?? $this->admin)->id))
            ->post("/company-admin/employees/{$target->id}/anonymise", $confirm === null ? [] : ['confirm_name' => $confirm]);
    }

    // ------------------------------------------------------- the confirmation

    public function test_it_needs_the_name_typed(): void
    {
        $this->anonymise()->assertSessionHasErrors('confirm_name');

        $this->assertNull($this->employee->fresh()->anonymised_at);
        $this->assertSame('Alice Sharma', $this->employee->fresh()->name);
    }

    public function test_a_wrong_name_removes_nothing(): void
    {
        foreach (['Alice', 'alice sharma', 'Bob Kumar'] as $wrong) {
            $this->anonymise($wrong)->assertSessionHasErrors('confirm_name');
        }

        $this->assertSame('Alice Sharma', $this->employee->fresh()->name);
    }

    /**
     * The guard has to be on the server: the request can be sent without ever
     * loading the page, and a test sends it.
     */
    public function test_the_check_is_server_side(): void
    {
        $this->anonymise('Bob Kumar')->assertSessionHasErrors('confirm_name');

        $this->assertNull($this->employee->fresh()->anonymised_at);
    }

    public function test_the_right_name_removes_their_details(): void
    {
        $this->anonymise('Alice Sharma')->assertSessionHasNoErrors();

        $employee = $this->employee->fresh();

        $this->assertNotNull($employee->anonymised_at);
        $this->assertSame($this->admin->id, $employee->anonymised_by);
    }

    public function test_surrounding_whitespace_is_forgiven(): void
    {
        $this->anonymise('  Alice Sharma  ')->assertSessionHasNoErrors();

        $this->assertNotNull($this->employee->fresh()->anonymised_at);
    }

    // ---------------------------------------------------- the identity is gone

    public function test_nothing_identifying_survives_on_the_employee(): void
    {
        $this->anonymise('Alice Sharma');

        $employee = $this->employee->fresh();

        $this->assertSame(AnonymiseEmployee::REMOVED_NAME, $employee->name);
        $this->assertNull($employee->email);
        $this->assertNull($employee->external_id);
        $this->assertSame('ANON-'.$employee->id, $employee->employee_code);
        $this->assertSame('inactive', $employee->status);
        $this->assertFalse($employee->is_meal_eligible);
    }

    public function test_nothing_identifying_survives_on_the_login(): void
    {
        $this->anonymise('Alice Sharma');

        $user = $this->employeeUser->fresh();

        $this->assertSame(AnonymiseEmployee::REMOVED_NAME, $user->name);
        $this->assertStringNotContainsString('alice', strtolower((string) $user->email));
        $this->assertNull($user->login_code);
        $this->assertFalse($user->is_active);
        $this->assertFalse(Hash::check('password-1', $user->password));
    }

    /**
     * The mail suffix the application already treats as unreachable, so nothing
     * ever tries to write to it.
     */
    public function test_the_replacement_address_is_never_mailed(): void
    {
        $this->anonymise('Alice Sharma');

        $this->assertFalse($this->employeeUser->fresh()->canReceiveMail());
    }

    public function test_they_cannot_sign_in_afterwards(): void
    {
        $this->anonymise('Alice Sharma');
        $this->post('/logout');
        $this->flushSession();

        $this->post('/login', ['identifier' => 'alice.sharma@alpha1.test', 'password' => 'password-1'])
            ->assertSessionHasErrors();

        $this->post('/login', [
            'company_code' => 'ALPHA1', 'login_code' => 'ALPHA1001', 'password' => 'password-1',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }

    /**
     * Free text on a skip is exactly the sort of detail this is meant to erase.
     */
    public function test_reason_text_on_their_skips_is_cleared(): void
    {
        $this->anonymise('Alice Sharma');

        $this->assertNull(Skip::where('employee_id', $this->employee->id)->first()->reason);
    }

    /**
     * Cleared, or the next pull would match them again by their id on the HR
     * side and put the name straight back.
     */
    public function test_the_hrms_link_is_broken_so_a_pull_cannot_re_identify_them(): void
    {
        $this->anonymise('Alice Sharma');

        $this->assertNull($this->employee->fresh()->external_id);
        $this->assertSame(0, Employee::where('external_id', 'hr-66e0bb01')->count());
    }

    // ------------------------------------------------------ the counts survive

    /**
     * The other half. Deleting the row would have taken the skip with it.
     */
    public function test_their_skips_are_kept_so_the_history_still_adds_up(): void
    {
        $before = Skip::where('company_id', $this->company->id)->count();

        $this->anonymise('Alice Sharma');

        $this->assertSame($before, Skip::where('company_id', $this->company->id)->count());

        $skip = Skip::where('employee_id', $this->employee->id)->first();

        $this->assertNotNull($skip, 'the skip was destroyed');
        $this->assertSame('hr', $skip->source);
        $this->assertNull($skip->cancelled_at);
    }

    public function test_the_locked_count_is_untouched(): void
    {
        $before = MealCount::where('company_id', $this->company->id)
            ->get(['date', 'base_eligible_count', 'skip_count', 'final_expected_count', 'locked_at'])->toJson();

        $this->anonymise('Alice Sharma');

        $this->assertSame(
            $before,
            MealCount::where('company_id', $this->company->id)
                ->get(['date', 'base_eligible_count', 'skip_count', 'final_expected_count', 'locked_at'])->toJson(),
        );
    }

    /**
     * The skip stays counted, so the snapshot remains explicable from the rows
     * behind it: that day's one `hr` skip is still there and still attributed.
     *
     * What does NOT survive is a live recomputation of that day's total, and
     * that is not this feature's doing. CalculateExpectedMeals counts the
     * employees who are eligible *now*, so base_eligible_count already moves
     * for any past date whenever anybody leaves or is marked ineligible -
     * which is precisely why locked snapshots exist and why they, not a
     * recomputation, are the historical record.
     *
     * Asserted rather than glossed over, because someone reading
     * "the history adds up" deserves to know which part does.
     */
    public function test_their_skip_is_still_counted_for_that_day(): void
    {
        $before = (new CalculateExpectedMeals)->execute($this->company, '2026-10-02');

        $this->anonymise('Alice Sharma');

        $after = (new CalculateExpectedMeals)->execute($this->company, '2026-10-02');

        $this->assertSame($before['skip_count'], $after['skip_count']);
        $this->assertSame($before['breakdown'], $after['breakdown']);

        // And the live base does drop, because they are no longer an employee.
        $this->assertSame($before['base_eligible_count'] - 1, $after['base_eligible_count']);
    }

    /**
     * So the authoritative figure is the snapshot, and it is byte-for-byte the
     * same afterwards. This is the assertion that matters for a billing
     * dispute.
     */
    public function test_the_snapshot_remains_the_authoritative_past_figure(): void
    {
        $snapshot = MealCount::where('company_id', $this->company->id)
            ->where('date', '2026-10-02')->firstOrFail();

        $before = [$snapshot->base_eligible_count, $snapshot->skip_count, $snapshot->final_expected_count];

        $this->anonymise('Alice Sharma');

        $after = MealCount::where('company_id', $this->company->id)
            ->where('date', '2026-10-02')->firstOrFail();

        $this->assertSame($before, [$after->base_eligible_count, $after->skip_count, $after->final_expected_count]);
        $this->assertSame(2, $after->base_eligible_count);
        $this->assertSame(1, $after->final_expected_count);
    }

    /**
     * Future meals are a different question: they are no longer an employee, so
     * they should stop being counted from now on.
     */
    public function test_they_stop_being_counted_for_future_days(): void
    {
        $before = (new CalculateExpectedMeals)->execute($this->company, '2026-10-13');

        $this->anonymise('Alice Sharma');

        $after = (new CalculateExpectedMeals)->execute($this->company, '2026-10-13');

        $this->assertSame($before['base_eligible_count'] - 1, $after['base_eligible_count']);
    }

    // -------------------------------------------------------------- the limits

    public function test_asking_twice_changes_nothing_the_second_time(): void
    {
        $this->anonymise('Alice Sharma');

        $first = $this->employee->fresh();

        // The name is already the placeholder, so that is what must be typed.
        $this->anonymise(AnonymiseEmployee::REMOVED_NAME)->assertSessionHasNoErrors();

        $second = $this->employee->fresh();

        $this->assertEquals($first->anonymised_at, $second->anonymised_at);
        $this->assertSame($first->employee_code, $second->employee_code);
    }

    public function test_another_tenants_employee_cannot_be_anonymised(): void
    {
        $this->anonymise('Carol Beta', $this->theirEmployee)->assertForbidden();

        $theirs = $this->theirEmployee->fresh();

        $this->assertNull($theirs->anonymised_at);
        $this->assertSame('Carol Beta', $theirs->name);
        $this->assertSame('carol@beta01.test', $theirs->email);
    }

    public function test_an_employee_cannot_anonymise_anybody(): void
    {
        $this->actingAs(User::findOrFail($this->employeeUser->id))
            ->post("/company-admin/employees/{$this->employee->id}/anonymise", ['confirm_name' => 'Alice Sharma'])
            ->assertForbidden();

        $this->assertNull($this->employee->fresh()->anonymised_at);
    }

    public function test_a_guest_cannot_anonymise_anybody(): void
    {
        $this->post("/company-admin/employees/{$this->employee->id}/anonymise", ['confirm_name' => 'Alice Sharma'])
            ->assertRedirect('/login');

        $this->assertNull($this->employee->fresh()->anonymised_at);
    }

    public function test_an_employee_with_no_login_can_still_be_anonymised(): void
    {
        $noLogin = Employee::where('employee_code', 'ALPHA1002')->firstOrFail();

        $this->anonymise('Bob Kumar', $noLogin)->assertSessionHasNoErrors();

        $this->assertNotNull($noLogin->fresh()->anonymised_at);
        $this->assertNull($noLogin->fresh()->email);
    }

    // ------------------------------------------------------------- the screen

    public function test_the_roster_reports_who_has_been_removed(): void
    {
        $this->anonymise('Alice Sharma');

        $props = $this->actingAs(User::findOrFail($this->admin->id))
            ->get('/company-admin/employees')
            ->getOriginalContent()->getData()['page']['props'];

        $row = collect($props['employees']['data'])->firstWhere('employee_code', 'ANON-'.$this->employee->id);

        $this->assertNotNull($row, 'the anonymised employee vanished from the roster');
        $this->assertNotNull($row['anonymised_at']);
        $this->assertSame(AnonymiseEmployee::REMOVED_NAME, $row['name']);
    }

    public function test_the_screen_asks_for_the_name_rather_than_using_a_confirm(): void
    {
        $page = file_get_contents(resource_path('js/Pages/CompanyAdmin/Employees/Index.vue'));

        $this->assertStringContainsString('beginAnonymise', $page);
        $this->assertStringContainsString('anonymiseForm.confirm_name', $page);
        $this->assertStringContainsString('to confirm', $page);
    }

    /**
     * The export has to keep working for a company that has removed someone,
     * and it must not put the name back.
     */
    public function test_the_export_still_works_and_carries_no_removed_name(): void
    {
        $this->anonymise('Alice Sharma');

        $response = $this->actingAs(User::findOrFail($this->admin->id))->get('/company-admin/export');

        $response->assertStatus(200);

        $this->assertStringNotContainsString('Alice Sharma', $response->streamedContent());
        $this->assertStringNotContainsString('Dental surgery', $response->streamedContent());
    }
}
