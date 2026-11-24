<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\TiffinService;
use App\Models\User;
use App\Notifications\VendorWelcomeNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * How a vendor gets in, and what they see before anyone has paired them.
 *
 * There is no self-registration and that is deliberate: a super admin creates
 * the kitchen and its admin together. What these cover is the two places that
 * were silently wrong - a welcome mail that must stay switched off until
 * somebody decides otherwise, and a counts page that could not tell an
 * unfinished account from a Sunday.
 */
class VendorOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', false);

        $this->superAdmin = User::create([
            'name' => 'Platform', 'email' => 'root@mealbells.test',
            'password' => bcrypt('password'), 'role' => 'super_admin',
        ]);
    }

    /** @return array<string, string> */
    protected function vendorPayload(): array
    {
        return [
            'name' => 'Annapurna Tiffin', 'address' => '14 Mill Road',
            'contact_phone' => '9876543210',
            'admin_name' => 'Vendor Admin', 'admin_email' => 'vendor@annapurna.test',
        ];
    }

    public function test_the_welcome_email_is_off(): void
    {
        Notification::fake();

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/tiffin-services', $this->vendorPayload())
            ->assertSessionHas('temporary_password');

        // The switch is off in config, so nothing may be sent. A vendor is
        // still handed their password by the person who created the account.
        Notification::assertNothingSent();
    }

    public function test_the_welcome_email_sends_when_it_is_switched_on(): void
    {
        Notification::fake();
        config()->set('mealbells.vendor_welcome_email', true);

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/tiffin-services', $this->vendorPayload());

        $admin = User::where('email', 'vendor@annapurna.test')->firstOrFail();

        Notification::assertSentTo($admin, VendorWelcomeNotification::class);
    }

    /**
     * The password is never in the mail. It lives in two mailboxes and often a
     * third server's logs, which is no place for a credential.
     */
    public function test_the_welcome_email_never_carries_the_password(): void
    {
        $tiffin = TiffinService::create([
            'name' => 'Annapurna Tiffin', 'address' => '14 Mill Road', 'contact_phone' => '9876543210',
        ]);
        $admin = User::create([
            'name' => 'Vendor Admin', 'email' => 'vendor@annapurna.test',
            'password' => bcrypt('S3cretTemp!'), 'role' => 'tiffin_admin',
            'tiffin_service_id' => $tiffin->id, 'must_change_password' => true,
        ]);

        $body = (new VendorWelcomeNotification($tiffin))->toMail($admin)->render()->toHtml();

        $this->assertStringNotContainsString('S3cretTemp!', $body);
        $this->assertStringContainsString('Annapurna Tiffin', $body);
    }

    /** @return array<string, mixed> */
    protected function preparationProps(User $vendor): array
    {
        return $this->actingAs($vendor)->get('/tiffin-admin/preparation')
            ->getOriginalContent()->getData()['page']['props'];
    }

    protected function vendorUser(TiffinService $tiffin): User
    {
        return User::create([
            'name' => 'Vendor Admin', 'email' => 'vendor@annapurna.test',
            'password' => bcrypt('password'), 'role' => 'tiffin_admin',
            'tiffin_service_id' => $tiffin->id,
        ]);
    }

    public function test_an_unpaired_kitchen_is_told_it_is_unpaired(): void
    {
        $tiffin = TiffinService::create([
            'name' => 'Annapurna Tiffin', 'address' => '14 Mill Road', 'contact_phone' => '9876543210',
        ]);

        $this->assertFalse($this->preparationProps($this->vendorUser($tiffin))['isPaired']);
    }

    /**
     * The state that used to be indistinguishable from the one above: a
     * kitchen that is set up correctly and simply has nothing to cook today.
     */
    public function test_a_paired_kitchen_is_not_reported_as_unpaired(): void
    {
        $company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);
        CompanySetting::create([
            'company_id' => $company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);
        $tiffin = TiffinService::create([
            'name' => 'Annapurna Tiffin', 'address' => '14 Mill Road', 'contact_phone' => '9876543210',
        ]);
        CompanyTiffinAssignment::create([
            'company_id' => $company->id, 'tiffin_service_id' => $tiffin->id, 'is_active' => true,
        ]);

        // Sunday: no meal day for this company, so there is nothing to show -
        // which must not read as a half-finished account. Taken from the
        // clock rather than written down, because the page refuses a date
        // more than a fortnight out and a fixed one would rot.
        $sunday = Carbon::today('Asia/Kolkata')->next(Carbon::SUNDAY)->toDateString();

        $response = $this->actingAs($this->vendorUser($tiffin))
            ->get("/tiffin-admin/preparation?date={$sunday}");

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('TiffinAdmin/Preparation')
            ->where('isPaired', true)
            ->etc()
        );
    }

    public function test_the_page_renders_the_two_states_apart(): void
    {
        $page = file_get_contents(resource_path('js/Pages/TiffinAdmin/Preparation.vue'));

        $this->assertStringContainsString('v-if="!isPaired"', $page);
        $this->assertStringContainsString('not connected to a company yet', $page);
        $this->assertStringContainsString('No meals on this date', $page);

        // The sentence that could mean either thing.
        $this->assertStringNotContainsString('or none is active on this date', $page);
    }

    public function test_a_voluntary_password_change_is_not_announced_as_required(): void
    {
        $tiffin = TiffinService::create([
            'name' => 'Annapurna Tiffin', 'address' => '14 Mill Road', 'contact_phone' => '9876543210',
        ]);

        $this->actingAs($this->vendorUser($tiffin))->get('/change-password')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/ChangePassword')
                ->where('forced', false)
            );
    }

    public function test_an_account_on_a_handed_over_password_is_told_why(): void
    {
        $tiffin = TiffinService::create([
            'name' => 'Annapurna Tiffin', 'address' => '14 Mill Road', 'contact_phone' => '9876543210',
        ]);
        $vendor = $this->vendorUser($tiffin);
        $vendor->update(['must_change_password' => true]);

        $this->actingAs($vendor)->get('/change-password')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/ChangePassword')
                ->where('forced', true)
            );
    }

    public function test_every_signed_in_screen_offers_a_way_to_change_it(): void
    {
        $layout = file_get_contents(resource_path('js/Layouts/AppLayout.vue'));

        // The screen was always reachable; nothing linked to it, so the only
        // people who saw it were the ones sent there.
        $this->assertStringContainsString('href="/change-password"', $layout);
        $this->assertStringContainsString('aria-label="Change password"', $layout);
    }
}
