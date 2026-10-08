<?php

namespace Tests\Feature;

use App\Actions\Meal\ConfirmDailyCount;
use App\Actions\Meal\RecordPostCutoffChange;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyTiffinAssignment;
use App\Models\Employee;
use App\Models\MealCount;
use App\Models\TiffinService;
use App\Models\User;
use App\Notifications\AnomalyEscalationNotification;
use App\Notifications\DailyCountSummaryNotification;
use App\Notifications\DailyMealOverrideNotification;
use App\Notifications\SuperAdminCutoffErrorNotification;
use App\Notifications\VendorCountReadyNotification;
use App\Notifications\VendorLateChangeNotification;
use App\Notifications\WeeklyMenuPublishedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The three notifications that had to reach somebody outside the application.
 *
 * Seven of the eight were ['database'] only. For most of them that is right -
 * an employee or an HR admin is in MealBells already. For these three it was
 * the wrong channel:
 *
 * - the vendor's daily count is the one number the kitchen needs, and it
 *   arrived in a feed they would have to open every morning;
 * - a post-cutoff change by definition lands after the vendor was told a
 *   number, often mid-shift;
 * - a failed cutoff means no locked count and no vendor told anything, and the
 *   only sign of it sat in a feed nobody reads at 11am.
 *
 * HR and employee notifications stay in-app deliberately.
 */
class OperationalEmailTest extends TestCase
{
    use RefreshDatabase;

    protected TiffinService $tiffin;

    protected Company $companyA;

    protected Company $companyB;

    protected User $vendorUser;

    protected User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->tiffin = TiffinService::create([
            'name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890',
        ]);

        $this->vendorUser = User::create([
            'name' => 'Vendor', 'email' => 'vendor@royal.test', 'password' => bcrypt('password'),
            'role' => 'tiffin_admin', 'tiffin_service_id' => $this->tiffin->id,
        ]);

        foreach (['A' => 10, 'B' => 4] as $suffix => $employeeCount) {
            $company = Company::create(['name' => "Company {$suffix}", 'code' => "CO{$suffix}001"]);

            CompanySetting::create([
                'company_id' => $company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
                'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
            ]);

            CompanyTiffinAssignment::create([
                'company_id' => $company->id, 'tiffin_service_id' => $this->tiffin->id,
                'is_active' => true, 'assigned_at' => '2026-09-01',
            ]);

            for ($i = 1; $i <= $employeeCount; $i++) {
                Employee::create([
                    'company_id' => $company->id,
                    'employee_code' => "{$suffix}EMP".str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                    'name' => "Person {$suffix}{$i}", 'status' => 'active', 'is_meal_eligible' => true,
                ]);
            }

            $this->{'company'.$suffix} = $company;
        }

        $this->adminA = User::create([
            'name' => 'HR A', 'email' => 'hr@coa.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyA->id,
        ]);
    }

    protected function lock(Company $company): void
    {
        (new ConfirmDailyCount)->execute($company, '2026-10-05', $this->adminA);
    }

    /** Pull the mail body out of a sent notification. */
    protected function mailBody(object $notification): string
    {
        $mail = $notification->toMail($this->vendorUser);

        return implode("\n", array_map('strval', [
            $mail->subject,
            ...$mail->introLines,
            ...$mail->outroLines,
        ]));
    }

    public function test_the_vendor_is_emailed_as_well_as_notified_in_app_when_a_day_locks(): void
    {
        $this->lock($this->companyA);

        Notification::assertSentTo(
            $this->vendorUser,
            VendorCountReadyNotification::class,
            fn ($notification, array $channels) => $channels === ['database', 'mail'],
        );
    }

    public function test_the_count_mail_leads_with_the_company_and_the_number(): void
    {
        $this->lock($this->companyA);

        Notification::assertSentTo(
            $this->vendorUser,
            VendorCountReadyNotification::class,
            function (VendorCountReadyNotification $notification) {
                $body = $this->mailBody($notification);

                return str_contains($body, 'Company A')
                    && str_contains($body, '10 meals')
                    && str_contains($body, '2026-10-05');
            },
        );
    }

    /**
     * The vendor cooks for every company at once, so one company's number on
     * its own is not the morning's workload.
     */
    public function test_the_count_mail_breaks_the_day_down_by_company(): void
    {
        $this->lock($this->companyB);
        Notification::fake();
        $this->lock($this->companyA);

        Notification::assertSentTo(
            $this->vendorUser,
            VendorCountReadyNotification::class,
            function (VendorCountReadyNotification $notification) {
                $body = $this->mailBody($notification);

                return str_contains($body, 'Company A')
                    && str_contains($body, 'Company B')
                    && str_contains($body, 'Total: 14 meals');
            },
        );
    }

    /**
     * A number that can still move must not be presented as final.
     */
    public function test_a_company_not_yet_locked_is_labelled_in_the_breakdown(): void
    {
        // Company B has a snapshot but is not locked.
        MealCount::create([
            'company_id' => $this->companyB->id, 'tiffin_service_id' => $this->tiffin->id,
            'date' => '2026-10-05', 'base_eligible_count' => 4, 'skip_count' => 0, 'extra_count' => 0,
            'final_expected_count' => 4, 'breakdown' => [], 'status' => 'draft',
        ]);

        $this->lock($this->companyA);

        Notification::assertSentTo(
            $this->vendorUser,
            VendorCountReadyNotification::class,
            fn (VendorCountReadyNotification $n) => str_contains($this->mailBody($n), 'not locked yet'),
        );
    }

    public function test_one_company_alone_gets_no_redundant_breakdown(): void
    {
        $this->lock($this->companyA);

        Notification::assertSentTo(
            $this->vendorUser,
            VendorCountReadyNotification::class,
            fn (VendorCountReadyNotification $n) => ! str_contains($this->mailBody($n), 'Total:'),
        );
    }

    /**
     * The constraint that governs every vendor-facing message: how many, never
     * who.
     */
    public function test_no_vendor_mail_carries_employee_detail(): void
    {
        $employee = Employee::where('company_id', $this->companyA->id)->firstOrFail();
        $employee->update(['name' => 'Alice Secretname', 'email' => 'alice@secret.test']);

        $this->lock($this->companyA);

        Notification::assertSentTo(
            $this->vendorUser,
            VendorCountReadyNotification::class,
            function (VendorCountReadyNotification $notification) {
                $body = $this->mailBody($notification).json_encode($notification->toArray($this->vendorUser));

                return ! str_contains($body, 'Alice Secretname')
                    && ! str_contains($body, 'alice@secret.test')
                    && ! str_contains($body, 'AEMP001');
            },
        );
    }

    public function test_a_post_cutoff_change_emails_the_vendor_with_the_new_total(): void
    {
        $this->lock($this->companyA);
        Notification::fake();

        (new RecordPostCutoffChange)->execute($this->companyA, '2026-10-05', 3, 'Four guests', $this->adminA);

        Notification::assertSentTo(
            $this->vendorUser,
            VendorLateChangeNotification::class,
            function (VendorLateChangeNotification $notification, array $channels) {
                $body = $this->mailBody($notification);

                return $channels === ['database', 'mail']
                    && $notification->newTotal === 13
                    // The new total, not just '+3': the reader is mid-shift and
                    // should not have to remember what it was.
                    && str_contains($body, 'New total: 13 meals')
                    && str_contains($body, 'was 10')
                    && str_contains($body, 'Four guests');
            },
        );
    }

    public function test_the_update_mail_says_updated_in_its_subject(): void
    {
        $this->lock($this->companyA);
        Notification::fake();

        (new RecordPostCutoffChange)->execute($this->companyA, '2026-10-05', -2, 'Two away', $this->adminA);

        Notification::assertSentTo(
            $this->vendorUser,
            VendorLateChangeNotification::class,
            function (VendorLateChangeNotification $notification) {
                $subject = (string) $notification->toMail($this->vendorUser)->subject;

                return str_contains($subject, 'UPDATED')
                    && str_contains($subject, '8 meals');
            },
        );
    }

    public function test_a_super_admin_is_emailed_when_a_cutoff_fails(): void
    {
        $superAdmin = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test', 'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);

        $notification = new SuperAdminCutoffErrorNotification($this->companyA, '2026-10-05', '[Lock] boom');

        $this->assertSame(['database', 'mail'], $notification->via($superAdmin));

        $mail = $notification->toMail($superAdmin);
        $body = implode("\n", array_map('strval', [...$mail->introLines, ...$mail->outroLines]));

        $this->assertStringContainsString('boom', $body);
        // The consequence, which is not obvious from the error itself.
        $this->assertStringContainsString('has not been told how many meals to cook', $body);
    }

    /**
     * A login created without an address of its own carries a stand-in one that
     * is never written to. Mailing it would bounce or vanish; the in-app copy
     * still arrives.
     */
    public function test_a_login_with_a_placeholder_address_is_not_mailed(): void
    {
        $this->vendorUser->update([
            'email' => 'vendor.royal@royal'.config('mealbells.placeholder_email_suffix', '.local'),
        ]);

        $this->lock($this->companyA);

        Notification::assertSentTo(
            $this->vendorUser,
            VendorCountReadyNotification::class,
            fn ($notification, array $channels) => $channels === ['database'],
        );
    }

    /**
     * Stated as a test because it is a decision, not an oversight: these people
     * are in MealBells already, and mailing them every skip would make the
     * mail worthless.
     */
    public function test_hr_and_employee_notifications_stay_in_app(): void
    {
        $inAppOnly = [
            DailyCountSummaryNotification::class,
            AnomalyEscalationNotification::class,
            DailyMealOverrideNotification::class,
            WeeklyMenuPublishedNotification::class,
        ];

        foreach ($inAppOnly as $class) {
            $this->assertStringNotContainsString(
                'toMail',
                file_get_contents(app_path('Notifications/'.class_basename($class).'.php')),
                class_basename($class).' should stay in-app',
            );
        }
    }

    /**
     * The sending identity comes from config, and the framework's
     * hello@example.com default is gone.
     *
     * That default is a domain nobody operating this owns, so with
     * MAIL_FROM_ADDRESS unset every mail is sent from an address that fails SPF
     * and is quietly dropped by the receiving side - a failure that looks
     * exactly like a working mailer from this end.
     *
     * Asserted against the config file rather than the resolved value, because
     * the resolved value depends on whatever .env the test is run with.
     */
    public function test_the_sending_identity_has_no_framework_placeholder_left(): void
    {
        $config = file_get_contents(config_path('mail.php'));

        $this->assertStringNotContainsString("'hello@example.com'", $config);
        $this->assertStringContainsString('MAIL_FROM_ADDRESS', $config);
        $this->assertStringContainsString('MAIL_FROM_NAME', $config);

        $this->assertSame('MealBells', config('mail.from.name'));
    }

    public function test_both_env_examples_declare_a_sending_identity(): void
    {
        foreach (['.env.example', '.env.local.example'] as $file) {
            $contents = file_get_contents(base_path($file));

            $this->assertStringContainsString('MAIL_FROM_ADDRESS=', $contents, $file);
            $this->assertStringContainsString('MAIL_FROM_NAME=', $contents, $file);
            $this->assertStringNotContainsString('MAIL_FROM_ADDRESS="hello@example.com"', $contents, $file);
        }
    }

    public function test_the_mail_layout_is_branded_rather_than_the_framework_default(): void
    {
        $this->assertFileExists(resource_path('views/vendor/mail/html/header.blade.php'));

        $theme = file_get_contents(resource_path('views/vendor/mail/html/themes/default.css'));
        $footer = file_get_contents(resource_path('views/vendor/mail/html/message.blade.php'));

        // The app's accent, and no remote logo to be blocked or to 404.
        $this->assertStringContainsString('#10b981', $theme);
        $this->assertStringNotContainsString('laravel.com/img', file_get_contents(
            resource_path('views/vendor/mail/html/header.blade.php'),
        ));
        $this->assertStringNotContainsString('All rights reserved', $footer);
    }

    public function test_every_operational_notification_is_queued(): void
    {
        foreach ([
            new VendorCountReadyNotification('Company A', '2026-10-05', 10),
            new VendorLateChangeNotification('Company A', '2026-10-05', 2, 'Guests'),
            new SuperAdminCutoffErrorNotification($this->companyA, '2026-10-05', 'boom'),
        ] as $notification) {
            $this->assertInstanceOf(ShouldQueue::class, $notification);
        }
    }
}
