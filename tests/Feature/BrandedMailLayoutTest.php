<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MealCount;
use App\Models\TiffinService;
use App\Models\User;
use App\Notifications\VendorCountReadyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

/**
 * The branded layout, rendered for real.
 *
 * It is a set of Blade templates that only run when a mail is actually sent, so
 * every test that fakes notifications would pass with them broken. This one
 * puts a notification through the whole pipeline into the in-memory transport
 * and reads the HTML that came out.
 *
 * Deliberately a separate class: nothing here fakes the notification system,
 * which is what the rest of the suite does.
 */
class BrandedMailLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mail.default', 'array');
        config()->set('queue.default', 'sync');
    }

    /**
     * @return Collection<int, SentMessage>
     */
    protected function sentMessages(): Collection
    {
        return collect(Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_a_vendor_count_mail_renders_through_the_branded_layout(): void
    {
        $tiffin = TiffinService::create([
            'name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890',
        ]);

        $vendor = User::create([
            'name' => 'Vendor', 'email' => 'vendor@royal.test', 'password' => bcrypt('password'),
            'role' => 'tiffin_admin', 'tiffin_service_id' => $tiffin->id,
        ]);

        foreach ([['Company A', 'COA001', 10], ['Company B', 'COB001', 4]] as [$name, $code, $count]) {
            $company = Company::create(['name' => $name, 'code' => $code]);

            MealCount::create([
                'company_id' => $company->id, 'tiffin_service_id' => $tiffin->id,
                'date' => '2026-10-05', 'base_eligible_count' => $count, 'skip_count' => 0,
                'extra_count' => 0, 'final_expected_count' => $count, 'breakdown' => [],
                'status' => 'confirmed', 'locked_at' => now(),
            ]);
        }

        $vendor->notify(new VendorCountReadyNotification('Company A', '2026-10-05', 10, $tiffin->id));

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages, 'the notification produced no mail');

        $message = $messages->first()->getOriginalMessage();
        $html = (string) $message->getHtmlBody();

        $this->assertStringContainsString('Company A', $html);
        $this->assertStringContainsString('10 meals', $html);
        $this->assertStringContainsString('Total: 14 meals', $html);

        // Branded, not the framework default: the app's accent, a text
        // wordmark rather than a remote logo, and no stock footer.
        $this->assertStringContainsString('#10b981', $html);
        $this->assertStringContainsString('MealBells', $html);
        $this->assertStringNotContainsString('laravel.com/img', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);

        // Markdown actually rendered, rather than asterisks left in the body.
        $this->assertStringNotContainsString('**', $html);

        // A plain-text alternative exists, since several vendor mail clients
        // will only show that.
        $this->assertNotEmpty((string) $message->getTextBody());
    }

    public function test_the_subject_names_the_company_and_the_number(): void
    {
        $tiffin = TiffinService::create([
            'name' => 'Royal Tiffin', 'address' => 'Addr', 'contact_phone' => '1234567890',
        ]);

        $vendor = User::create([
            'name' => 'Vendor', 'email' => 'vendor@royal.test', 'password' => bcrypt('password'),
            'role' => 'tiffin_admin', 'tiffin_service_id' => $tiffin->id,
        ]);

        $vendor->notify(new VendorCountReadyNotification('Company A', '2026-10-05', 10));

        $subject = (string) $this->sentMessages()->first()->getOriginalMessage()->getSubject();

        $this->assertStringContainsString('MealBells', $subject);
        $this->assertStringContainsString('Company A', $subject);
        $this->assertStringContainsString('10 meals', $subject);
    }
}
