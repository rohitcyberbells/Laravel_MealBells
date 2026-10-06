<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\HrmsWebhookEvent;
use App\Models\Skip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The connect screen's test button used to call its own endpoint over HTTP,
 * which deadlocks a single-threaded server until it times out. These run with
 * stray HTTP requests forbidden, so a network call would fail the test outright.
 */
class HrmsTestEventButtonTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected Employee $employee;

    protected string $secret = 'whsec_button_test';

    protected function setUp(): void
    {
        parent::setUp();

        // Inertia's server-side render is itself an HTTP call and would trip the
        // guard below for reasons unrelated to this feature.
        config()->set('inertia.ssr.enabled', false);

        // No Http::fake(): any outgoing request now throws instead of passing,
        // which is what proves the test event no longer goes over the network.
        Http::preventStrayRequests();

        $this->company = Company::create(['name' => 'Acme Industries', 'code' => 'ACME01']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->admin = User::create([
            'name' => 'Acme HR', 'email' => 'hr@acme.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        CompanySetting::where('company_id', $this->company->id)->update(['primary_admin_id' => $this->admin->id]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'ACME001', 'external_id' => 'HR-ACME001',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);

        CompanyHrmsConnection::create([
            'company_id' => $this->company->id,
            'webhook_secret' => $this->secret,
            'auth' => 'signature',
            'secret_rotated_at' => now(),
        ]);
    }

    protected function press(array $payload = [])
    {
        return $this->actingAs($this->admin)
            ->from('/company-admin/hrms')
            ->post('/company-admin/hrms/test-event', array_merge([
                'event' => 'leave_approved',
                'employee' => 'HR-ACME001',
                'from' => '2026-10-06',
            ], $payload));
    }

    public function test_the_button_delivers_without_any_network_call(): void
    {
        $response = $this->press();

        $response->assertRedirect();
        $response->assertSessionHas('test_result');

        $result = session('test_result');

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertEquals(202, $result['status']);
        $this->assertTrue($result['employee_matched']);
    }

    public function test_the_event_is_recorded_and_applied(): void
    {
        $this->press();

        $result = session('test_result');
        $event = HrmsWebhookEvent::where('external_event_id', $result['event_id'])->sole();

        $this->assertEquals(HrmsWebhookEvent::STATUS_APPLIED, $event->status);

        // The outcome travels back for the message above the list.
        $this->assertEquals('applied', $result['event_status']);
        $this->assertStringContainsString('2026-10-06', $result['summary']);

        $skip = Skip::sole();
        $this->assertEquals($this->employee->id, $skip->employee_id);
        $this->assertEquals('leave', $skip->source);
    }

    public function test_the_new_event_appears_at_the_top_of_the_list(): void
    {
        $this->press();
        $eventId = session('test_result')['event_id'];

        $page = $this->actingAs($this->admin)->get('/company-admin/hrms');
        $page->assertStatus(200);

        $props = $page->getOriginalContent()->getData()['page']['props'];

        $this->assertEquals($eventId, $props['events'][0]['external_event_id']);
        $this->assertEquals('applied', $props['events'][0]['status']);
        $this->assertEquals(['2026-10-06'], $props['events'][0]['applied_days']);
    }

    public function test_a_request_the_receiver_refuses_is_reported_cleanly(): void
    {
        // The receiver is untouched and its checks still run in process. With no
        // tolerance at all the timestamp can never be inside the window, so the
        // signature check refuses - which is the path a mismatching secret takes
        // too, and it must surface as a message rather than a 500.
        config()->set('hrms.webhook_defaults.tolerance_seconds', -1);

        $response = $this->press();

        $response->assertRedirect('/company-admin/hrms');

        $result = session('test_result');

        $this->assertFalse($result['ok']);
        $this->assertEquals(401, $result['status']);
        $this->assertStringContainsString('401', $result['error']);

        // Refused before anything was recorded.
        $this->assertEquals(0, HrmsWebhookEvent::count());
        $this->assertEquals(0, Skip::count());
    }

    public function test_a_company_without_a_secret_gets_a_readable_error_not_a_500(): void
    {
        CompanyHrmsConnection::where('company_id', $this->company->id)->delete();

        $response = $this->press();

        $response->assertRedirect();

        $result = session('test_result');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No webhook secret', $result['error']);
        $this->assertEquals(0, HrmsWebhookEvent::count());
    }

    public function test_an_approval_without_an_employee_is_refused_without_a_500(): void
    {
        $response = $this->press(['employee' => null]);

        $response->assertRedirect();

        $result = session('test_result');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('employee reference is required', $result['error']);
    }

    public function test_an_unmatched_employee_reference_is_reported_as_blocked(): void
    {
        $this->press(['employee' => 'HR-NOBODY']);

        $result = session('test_result');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['employee_matched']);
        $this->assertEquals('blocked', $result['event_status']);
        $this->assertEquals(0, Skip::count());
    }

    public function test_an_employee_cannot_press_the_button(): void
    {
        $employeeUser = User::create([
            'name' => 'Alice', 'email' => 'alice@acme.test', 'password' => bcrypt('password'),
            'role' => 'employee', 'company_id' => $this->company->id,
        ]);
        $this->employee->update(['user_id' => $employeeUser->id]);

        $this->actingAs($employeeUser)
            ->post('/company-admin/hrms/test-event', ['employee' => 'HR-ACME001'])
            ->assertStatus(403);
    }

    public function test_the_outer_request_survives_the_sub_request(): void
    {
        // app()->handle() rebinds the container's request; if it were not put
        // back, the redirect this action returns would point at the API route.
        $this->press()->assertRedirect('/company-admin/hrms');
    }
}
