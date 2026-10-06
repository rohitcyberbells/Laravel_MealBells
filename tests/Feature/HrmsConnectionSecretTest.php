<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\HrmsWebhookEvent;
use App\Models\User;
use App\Services\Hrms\HrmsConnectionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HrmsConnectionSecretTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->companyA = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->companyA->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        Employee::create([
            'company_id' => $this->companyA->id, 'employee_code' => 'EMP101', 'external_id' => 'HR-1',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        $this->superAdmin = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test', 'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);
    }

    protected function storeSecret(string $secret): CompanyHrmsConnection
    {
        return CompanyHrmsConnection::create([
            'company_id' => $this->companyA->id,
            'webhook_secret' => $secret,
            'secret_rotated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function payload(string $eventId = 'evt-1'): array
    {
        return [
            'event_id' => $eventId,
            'event_type' => 'leave_approved',
            'occurred_at' => '2026-10-05T03:00:00Z',
            'leave' => [
                'id' => 'L-1', 'employee_id' => 'HR-1',
                'from_date' => '2026-10-06', 'to_date' => '2026-10-06',
            ],
        ];
    }

    protected function deliver(array $payload, string $secret)
    {
        $body = json_encode($payload);
        $timestamp = (string) now()->timestamp;

        return $this->withHeaders([
            'X-Hrms-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
            'X-Hrms-Timestamp' => $timestamp,
        ])->postJson("/api/hrms/{$this->companyA->code}/events", $payload);
    }

    public function test_a_secret_stored_in_the_database_authenticates_the_webhook(): void
    {
        // Nothing in config: the database is the only source.
        $this->storeSecret('whsec_from_db');

        $this->deliver($this->payload(), 'whsec_from_db')->assertStatus(202);
        $this->assertEquals(1, HrmsWebhookEvent::count());
    }

    public function test_the_database_secret_wins_over_the_config_secret(): void
    {
        config()->set("hrms.companies.{$this->companyA->id}.webhook", [
            'auth' => 'signature', 'secret' => 'whsec_from_config',
        ]);
        $this->storeSecret('whsec_from_db');

        $this->deliver($this->payload('evt-db'), 'whsec_from_db')->assertStatus(202);
        $this->deliver($this->payload('evt-config'), 'whsec_from_config')->assertStatus(401);

        $this->assertEquals(1, HrmsWebhookEvent::count());
    }

    public function test_config_still_works_as_a_fallback_when_no_row_exists(): void
    {
        config()->set("hrms.companies.{$this->companyA->id}.webhook", [
            'auth' => 'signature', 'secret' => 'whsec_from_config',
        ]);

        $this->deliver($this->payload(), 'whsec_from_config')->assertStatus(202);
    }

    public function test_a_company_with_no_secret_anywhere_is_refused(): void
    {
        $this->deliver($this->payload(), 'whsec_anything')->assertStatus(401);
        $this->assertEquals(0, HrmsWebhookEvent::count());
    }

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        $this->storeSecret('whsec_plain_value');

        $raw = DB::table('company_hrms_connections')
            ->where('company_id', $this->companyA->id)
            ->value('webhook_secret');

        $this->assertNotEquals('whsec_plain_value', $raw);
        $this->assertStringNotContainsString('whsec_plain_value', $raw);

        // Still readable through the cast.
        $this->assertEquals(
            'whsec_plain_value',
            CompanyHrmsConnection::where('company_id', $this->companyA->id)->sole()->webhook_secret
        );
    }

    public function test_rotating_returns_the_secret_once_and_invalidates_the_old_one(): void
    {
        $this->storeSecret('whsec_old');

        $response = $this->actingAs($this->superAdmin)
            ->post("/super-admin/companies/{$this->companyA->id}/hrms-secret");

        $response->assertRedirect();
        $response->assertSessionHas('hrms_secret');

        $flashed = session('hrms_secret');
        $this->assertNotEquals('whsec_old', $flashed['secret']);
        $this->assertStringContainsString("/api/hrms/{$this->companyA->code}/events", $flashed['webhook_url']);

        $this->deliver($this->payload('evt-old'), 'whsec_old')->assertStatus(401);
        $this->deliver($this->payload('evt-new'), $flashed['secret'])->assertStatus(202);
    }

    public function test_rotating_generates_a_row_for_a_company_that_had_none(): void
    {
        $this->actingAs($this->superAdmin)
            ->post("/super-admin/companies/{$this->companyA->id}/hrms-secret")
            ->assertRedirect();

        $connection = CompanyHrmsConnection::where('company_id', $this->companyA->id)->sole();
        $this->assertNotNull($connection->webhook_secret);
        $this->assertEquals($this->superAdmin->id, $connection->rotated_by);
        $this->assertNotNull($connection->secret_rotated_at);
    }

    public function test_the_dashboard_reports_connection_state_without_the_secret(): void
    {
        $this->storeSecret('whsec_db');

        $response = $this->actingAs($this->superAdmin)->get('/super-admin/dashboard');
        $response->assertStatus(200);

        $props = $response->getOriginalContent()->getData()['page']['props'];
        $company = collect($props['companies'])->firstWhere('id', $this->companyA->id);

        $this->assertTrue($company['hrms']['has_secret']);
        $this->assertStringContainsString('/api/hrms/ALPHA1/events', $company['hrms']['webhook_url']);

        // The plaintext must never reach the page outside the one-time flash.
        $this->assertStringNotContainsString('whsec_db', json_encode($props));
    }

    public function test_the_secret_shows_on_one_render_and_is_gone_on_the_next(): void
    {
        $this->actingAs($this->superAdmin)
            ->post("/super-admin/companies/{$this->companyA->id}/hrms-secret");

        // The render straight after rotating is the one time it is visible.
        $first = $this->actingAs($this->superAdmin)->get('/super-admin/dashboard');
        $this->assertNotNull($first->getOriginalContent()->getData()['page']['props']['hrms_secret']);

        // Flash data is consumed, so a reload no longer carries it.
        $second = $this->actingAs($this->superAdmin)->get('/super-admin/dashboard');
        $this->assertNull($second->getOriginalContent()->getData()['page']['props']['hrms_secret']);
    }

    public function test_a_company_admin_cannot_rotate_a_secret(): void
    {
        $companyAdmin = User::create([
            'name' => 'Admin A', 'email' => 'admin@a.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->companyA->id,
        ]);

        $this->actingAs($companyAdmin)
            ->post("/super-admin/companies/{$this->companyA->id}/hrms-secret")
            ->assertStatus(403);

        $this->assertEquals(0, CompanyHrmsConnection::count());
    }

    public function test_the_simulator_signs_with_the_database_secret(): void
    {
        $this->storeSecret('whsec_from_db');
        config()->set('app.url', 'http://mealbells.test');
        Http::fake(['*' => Http::response([], 202)]);

        $this->artisan('hrms:simulate', [
            '--company' => 'ALPHA1', '--employee' => 'HR-1', '--from' => '2026-10-06',
        ])->assertExitCode(0);

        Http::assertSent(function ($request) {
            $timestamp = $request->header('X-Hrms-Timestamp')[0];

            return $request->header('X-Hrms-Signature')[0]
                === hash_hmac('sha256', $timestamp.'.'.$request->body(), 'whsec_from_db');
        });
    }

    public function test_the_resolver_reports_whether_a_secret_exists(): void
    {
        $resolver = app(HrmsConnectionResolver::class);

        $this->assertFalse($resolver->hasSecret($this->companyA));

        $this->storeSecret('whsec_db');

        $this->assertTrue($resolver->hasSecret($this->companyA));
    }
}
