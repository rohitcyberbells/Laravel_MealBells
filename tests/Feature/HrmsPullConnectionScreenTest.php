<?php

namespace Tests\Feature;

use App\Actions\Hrms\PullHrmsLeaves;
use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\HrmsPullRun;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The company admin's pull connection form, its test button, and the promise
 * that the credentials never leave the server.
 */
class HrmsPullConnectionScreenTest extends TestCase
{
    use RefreshDatabase;

    protected const BASE = 'https://hrms.cyberpulse.test';

    protected Company $company;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('inertia.ssr.enabled', false);

        $this->company = Company::create(['name' => 'Alpha Corp', 'code' => 'ALPHA1']);

        CompanySetting::create([
            'company_id' => $this->company->id, 'cutoff_time' => '11:00:00', 'timezone' => 'Asia/Kolkata',
            'wfh_auto_skip' => true, 'meal_days' => [1, 2, 3, 4, 5],
        ]);

        $this->admin = User::create([
            'name' => 'Alpha HR', 'email' => 'hr@alpha.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $this->company->id,
        ]);

        Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'EMP101',
            'external_id' => '66e0bb0000000000000000e1', 'email' => 'alice@alpha.test',
            'name' => 'Alice', 'status' => 'active', 'is_meal_eligible' => true,
        ]);
    }

    /** @return array<string, mixed> */
    protected function hrmsProps(): array
    {
        return $this->actingAs($this->admin)->get('/company-admin/hrms')
            ->getOriginalContent()->getData()['page']['props'];
    }

    protected function save(array $overrides = [])
    {
        return $this->actingAs($this->admin)->from('/company-admin/hrms')
            ->post('/company-admin/hrms/pull-connection', array_merge([
                'base_url' => self::BASE,
                'email' => 'integration@alpha.test',
                'password' => 'vendor-secret-9',
                'adapter' => 'cyberpulse',
            ], $overrides));
    }

    protected function fakeVendor(int $fetchStatus = 200): void
    {
        Http::fake([
            self::BASE.'/api/employee/login' => Http::response(['token' => 'jwt-from-vendor'], 200),
            self::BASE.'/api/leave/fetchAll' => Http::response(
                $fetchStatus === 200
                    ? json_decode(file_get_contents(base_path('tests/Fixtures/CyberPulse/fetchAll.json')), true)
                    : ['message' => 'upstream error'],
                $fetchStatus,
            ),
        ]);
    }

    // -------------------------------------------------------------- saving

    public function test_an_admin_can_save_the_pull_connection(): void
    {
        $this->save()->assertRedirect('/company-admin/hrms')->assertSessionHasNoErrors();

        $connection = CompanyHrmsConnection::where('company_id', $this->company->id)->sole();

        $this->assertEquals(self::BASE, $connection->pull_base_url);
        $this->assertEquals('integration@alpha.test', $connection->pull_email);
        $this->assertEquals('vendor-secret-9', $connection->pull_password);
        $this->assertEquals('cyberpulse', $connection->pull_adapter);
    }

    public function test_the_page_reports_that_a_password_is_set_without_sending_it(): void
    {
        $this->save();

        $pull = $this->hrmsProps()['pull'];

        $this->assertTrue($pull['has_password']);
        $this->assertArrayNotHasKey('password', $pull);
        $this->assertEquals(self::BASE, $pull['base_url']);
    }

    /**
     * Saving a URL change must not quietly clear the password and break the
     * connection, so a blank field keeps what is stored.
     */
    public function test_a_blank_password_keeps_the_stored_one(): void
    {
        $this->save();

        $this->save(['base_url' => 'https://hrms2.cyberpulse.test', 'password' => ''])
            ->assertSessionHasNoErrors();

        $connection = CompanyHrmsConnection::where('company_id', $this->company->id)->sole();

        $this->assertEquals('https://hrms2.cyberpulse.test', $connection->pull_base_url);
        $this->assertEquals('vendor-secret-9', $connection->pull_password);
    }

    public function test_the_first_save_requires_a_password(): void
    {
        $this->save(['password' => ''])->assertSessionHasErrors('password');

        $this->assertDatabaseCount('company_hrms_connections', 0);
    }

    /**
     * This form sends a password to a third-party host; over plain http it would
     * travel in clear. SafeHrmsBaseUrl carries the detail - these prove the form
     * is actually wired to it.
     */
    public function test_a_plain_http_url_is_refused(): void
    {
        $this->save(['base_url' => 'http://hrms.cyberpulse.test'])->assertSessionHasErrors('base_url');

        $this->assertDatabaseCount('company_hrms_connections', 0);
    }

    /**
     * The host is supplied by an admin and the server then signs in to it, so
     * without this the form is a way to probe the private network from inside
     * it - 169.254.169.254 being instance metadata on a cloud host.
     */
    public function test_a_private_address_is_refused_by_the_form(): void
    {
        foreach (['https://10.0.0.5/api', 'https://192.168.1.10', 'https://169.254.169.254/'] as $url) {
            $this->save(['base_url' => $url])->assertSessionHasErrors('base_url');
        }

        $this->assertDatabaseCount('company_hrms_connections', 0);
    }

    /**
     * A developer's stub HRMS has no certificate, and forcing one would only get
     * the check disabled outright. The suite runs as 'testing', which is relaxed.
     *
     * The production half of this is asserted in SafeHrmsBaseUrlTest rather than
     * here: flipping the app environment mid-request changes the session cookie
     * to secure-only, the test request loses its session, and the form is never
     * reached at all - so a test written that way would pass without proving
     * anything.
     */
    public function test_a_local_stub_over_http_is_accepted_in_a_local_environment(): void
    {
        $this->save(['base_url' => 'http://127.0.0.1:8901'])->assertSessionHasNoErrors();

        $this->assertEquals(
            'http://127.0.0.1:8901',
            CompanyHrmsConnection::where('company_id', $this->company->id)->sole()->pull_base_url,
        );
    }

    public function test_the_adapter_must_be_one_we_configured(): void
    {
        // Otherwise a form post could name any class.
        $this->save(['adapter' => 'App\Services\Hrms\Adapters\GenericHrmsAdapter'])
            ->assertSessionHasErrors('adapter');

        $this->save(['adapter' => 'whatever'])->assertSessionHasErrors('adapter');
    }

    /**
     * The cached token was issued for the old identity or by the old host.
     */
    public function test_changing_a_credential_invalidates_the_cached_token(): void
    {
        $this->save();
        $this->holdToken();

        $this->save(['email' => 'someone.else@alpha.test', 'password' => '']);

        $connection = CompanyHrmsConnection::where('company_id', $this->company->id)->sole();
        $this->assertNull($connection->pull_token);
        $this->assertNull($connection->pull_token_expires_at);
    }

    /**
     * Saving the form unchanged must clear it too.
     *
     * Re-saving is how someone reacts to a connection that is misbehaving, and
     * the likeliest cause is a token the vendor no longer honours. Comparing the
     * values first meant the one action a person takes to fix it left the broken
     * token in place, and it had to be cleared by hand.
     */
    public function test_re_saving_the_same_credentials_still_clears_the_token(): void
    {
        $this->save();
        $this->holdToken();

        // Byte-for-byte identical, password field left blank.
        $this->save(['password' => ''])->assertSessionHasNoErrors();

        $connection = CompanyHrmsConnection::where('company_id', $this->company->id)->sole();
        $this->assertNull($connection->pull_token);
        $this->assertNull($connection->pull_token_expires_at);

        // And the credentials themselves survived.
        $this->assertEquals(self::BASE, $connection->pull_base_url);
        $this->assertEquals('vendor-secret-9', $connection->pull_password);
    }

    /**
     * A token the vendor refuses is dropped and one fresh login is attempted.
     * 403 counts as well as 401 - vendors are inconsistent about which they use.
     */
    #[DataProvider('tokenRefusalStatuses')]
    public function test_a_refused_token_is_dropped_and_a_fresh_login_is_attempted(int $status): void
    {
        $this->save();
        $this->holdToken();

        $calls = 0;

        Http::fake([
            self::BASE.'/api/employee/login' => Http::response(['token' => 'brand-new-token'], 200),
            self::BASE.'/api/leave/fetchAll' => function () use (&$calls, $status) {
                $calls++;

                // Refuse the held token once, accept whatever comes next.
                return $calls === 1
                    ? Http::response(['message' => 'bad token'], $status)
                    : Http::response(['success' => true, 'data' => []], 200);
            },
        ]);

        $summary = app(PullHrmsLeaves::class)
            ->execute($this->company->fresh('setting'));

        $this->assertTrue($summary['ok'], 'the retry should have succeeded');
        $this->assertEquals('brand-new-token', CompanyHrmsConnection::where('company_id', $this->company->id)->sole()->pull_token);
        $this->assertEquals(2, $calls, 'exactly one retry, not a loop');
    }

    /** @return array<int, array<int, int>> */
    public static function tokenRefusalStatuses(): array
    {
        return [[401], [403]];
    }

    /**
     * Even when the once-a-minute cap then refuses the login, the dead token is
     * gone - so the next run starts clean instead of presenting it again.
     */
    public function test_a_refused_token_is_dropped_even_if_the_login_cooldown_blocks(): void
    {
        $this->save();
        $this->holdToken();

        CompanyHrmsConnection::where('company_id', $this->company->id)->first()
            ->forceFill(['last_login_at' => now()->subSeconds(2)])->save();

        Http::fake([
            self::BASE.'/api/leave/fetchAll' => Http::response(['message' => 'bad token'], 401),
        ]);

        $summary = app(PullHrmsLeaves::class)
            ->execute($this->company->fresh('setting'));

        $this->assertFalse($summary['ok']);
        $this->assertStringContainsString('Refusing to log in', (string) $summary['error']);
        $this->assertNull(CompanyHrmsConnection::where('company_id', $this->company->id)->sole()->pull_token);
    }

    protected function holdToken(string $token = 'old-token'): void
    {
        CompanyHrmsConnection::where('company_id', $this->company->id)->first()
            ->forceFill(['pull_token' => $token, 'pull_token_expires_at' => now()->addDays(10)])->save();
    }

    // ---------------------------------------------------------------- testing

    public function test_the_test_button_reports_counts_and_writes_nothing(): void
    {
        $this->save();
        $this->fakeVendor();

        $this->actingAs($this->admin)->from('/company-admin/hrms')
            ->post('/company-admin/hrms/pull-test')
            ->assertRedirect('/company-admin/hrms');

        $result = $this->hrmsProps()['pull_test'];

        $this->assertTrue($result['ok']);
        $this->assertEquals(7, $result['fetched']);
        $this->assertEquals(1, $result['applied']);
        $this->assertEquals(1, $result['ignored'], 'the half-day leave');
        // Bob's WFH and the ghost: neither employee exists in this company.
        $this->assertEquals(2, $result['unknown_employee']);

        // A dry run: no skips, no events, no run row.
        $this->assertDatabaseCount('skips', 0);
        $this->assertDatabaseCount('hrms_webhook_events', 0);
        $this->assertDatabaseCount('hrms_pull_runs', 0);
    }

    /**
     * A count alone is not actionable: an admin can only fix the mapping if they
     * are told who failed to match.
     */
    public function test_the_test_button_names_the_employees_that_did_not_match(): void
    {
        $this->save();
        $this->fakeVendor();

        $this->actingAs($this->admin)->from('/company-admin/hrms')
            ->post('/company-admin/hrms/pull-test');

        $unmatched = collect($this->hrmsProps()['pull_test']['unmatched']);

        $this->assertCount(2, $unmatched);

        // Bob's WFH and the ghost leave, both naming someone we cannot place.
        $this->assertEqualsCanonicalizing(
            ['bob@alpha.test', 'nobody@alpha.test'],
            $unmatched->pluck('employee_email')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['66e0bb0000000000000000e2', '66e0bb0000000000000000e9'],
            $unmatched->pluck('employee_ref')->all(),
        );

        // Employees that DID match are not in the list.
        $this->assertNotContains('alice@alpha.test', $unmatched->pluck('employee_email')->all());
    }

    /**
     * These identifiers are useful on screen for one render and have no business
     * in a stored column - the run history is deliberately free of PII.
     */
    public function test_the_unmatched_list_is_never_persisted(): void
    {
        $this->save();
        $this->fakeVendor();
        $this->artisan('hrms:pull', ['company' => 'ALPHA1']);

        $connection = CompanyHrmsConnection::where('company_id', $this->company->id)->sole();

        $this->assertArrayNotHasKey('unmatched', $connection->last_pull_summary);
        // The count still is, so the health page can flag it.
        $this->assertEquals(2, $connection->last_pull_summary['unknown_employee']);

        $stored = json_encode([
            (array) DB::table('company_hrms_connections')->first(),
            (array) DB::table('hrms_pull_runs')->first(),
        ]);

        foreach (['bob@alpha.test', 'nobody@alpha.test', '66e0bb0000000000000000e2'] as $pii) {
            $this->assertStringNotContainsString($pii, $stored);
        }
    }

    public function test_the_command_lists_the_unmatched_employees(): void
    {
        $this->save();
        $this->fakeVendor();

        $this->artisan('hrms:pull', ['company' => 'ALPHA1'])
            ->expectsOutputToContain('matched no employee in MealBells')
            ->expectsOutputToContain('nobody@alpha.test')
            ->assertSuccessful();
    }

    public function test_the_test_button_reports_a_failure_rather_than_erroring(): void
    {
        $this->save();
        $this->fakeVendor(fetchStatus: 500);

        $this->actingAs($this->admin)->from('/company-admin/hrms')
            ->post('/company-admin/hrms/pull-test')
            ->assertRedirect('/company-admin/hrms');

        $result = $this->hrmsProps()['pull_test'];

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('500', (string) $result['error']);
    }

    public function test_the_test_button_is_honest_when_no_credentials_exist(): void
    {
        $this->actingAs($this->admin)->from('/company-admin/hrms')
            ->post('/company-admin/hrms/pull-test');

        $result = $this->hrmsProps()['pull_test'];

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No CyberPulse credentials', (string) $result['error']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ who may act

    public function test_an_employee_cannot_reach_the_pull_connection(): void
    {
        $employeeUser = User::create([
            'name' => 'Alice', 'email' => 'alice.user@alpha.test', 'password' => bcrypt('password'),
            'role' => 'employee', 'company_id' => $this->company->id, 'login_code' => 'EMP101',
        ]);

        $this->actingAs($employeeUser)->get('/company-admin/hrms')->assertStatus(403);
        $this->actingAs($employeeUser)->post('/company-admin/hrms/pull-connection', [
            'base_url' => self::BASE, 'email' => 'x@y.test', 'password' => 'secret123', 'adapter' => 'cyberpulse',
        ])->assertStatus(403);
        $this->actingAs($employeeUser)->post('/company-admin/hrms/pull-test')->assertStatus(403);

        $this->assertDatabaseCount('company_hrms_connections', 0);
    }

    public function test_a_guest_cannot_reach_it_either(): void
    {
        $this->post('/company-admin/hrms/pull-connection', [
            'base_url' => self::BASE, 'email' => 'x@y.test', 'password' => 'secret123', 'adapter' => 'cyberpulse',
        ])->assertRedirect('/login');

        $this->assertDatabaseCount('company_hrms_connections', 0);
    }

    public function test_an_admin_of_another_company_cannot_change_this_one(): void
    {
        $other = Company::create(['name' => 'Beta Corp', 'code' => 'BETA1']);
        $otherAdmin = User::create([
            'name' => 'Beta HR', 'email' => 'hr@beta.test', 'password' => bcrypt('password'),
            'role' => 'company_admin', 'company_id' => $other->id,
        ]);

        $this->actingAs($otherAdmin)->post('/company-admin/hrms/pull-connection', [
            'base_url' => 'https://evil.test', 'email' => 'x@y.test', 'password' => 'secret123', 'adapter' => 'cyberpulse',
        ]);

        // Their own row, never ours.
        $this->assertDatabaseMissing('company_hrms_connections', ['company_id' => $this->company->id]);
        $this->assertDatabaseHas('company_hrms_connections', ['company_id' => $other->id]);
    }

    // ---------------------------------------------------------------- secrecy

    /**
     * The credentials must never reach a page, a prop or any JSON - not on the
     * connect screen, not on the health page, not through the model's own
     * serialisation.
     */
    public function test_the_credentials_never_appear_in_any_response(): void
    {
        $this->save();
        $this->fakeVendor();
        $this->actingAs($this->admin)->post('/company-admin/hrms/pull-test');

        $root = User::create([
            'name' => 'Root', 'email' => 'root@mealbells.test',
            'password' => bcrypt('password'), 'role' => 'super_admin',
        ]);

        $responses = [
            json_encode($this->hrmsProps()),
            $this->actingAs($this->admin)->get('/company-admin/hrms')->getContent(),
            json_encode($this->actingAs($root)->get('/super-admin/health')
                ->getOriginalContent()->getData()['page']['props']),
            $this->actingAs($root)->get('/super-admin/dashboard')->getContent(),
            // And the model serialised directly, which is what an accidental
            // ->toJson() or a resource would emit.
            CompanyHrmsConnection::where('company_id', $this->company->id)->sole()->toJson(),
        ];

        foreach ($responses as $i => $body) {
            foreach (['vendor-secret-9', 'jwt-from-vendor', self::BASE] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $body, "secret '{$secret}' leaked in response #{$i}");
            }
        }
    }

    public function test_every_pull_credential_is_encrypted_at_rest(): void
    {
        $this->save();
        $this->fakeVendor();
        $this->actingAs($this->admin)->post('/company-admin/hrms/pull-test');

        $raw = (array) DB::table('company_hrms_connections')->where('company_id', $this->company->id)->first();

        foreach (['vendor-secret-9', 'integration@alpha.test', self::BASE] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($raw));
        }
    }

    public function test_the_password_and_token_are_hidden_on_the_model(): void
    {
        $connection = new CompanyHrmsConnection;

        foreach (['pull_password', 'pull_token', 'pull_base_url', 'pull_email', 'webhook_secret'] as $column) {
            $this->assertContains($column, $connection->getHidden(), "{$column} is not hidden");
        }
    }

    public function test_the_pull_credentials_are_encrypted_casts(): void
    {
        $casts = (new CompanyHrmsConnection)->getCasts();

        foreach (['pull_base_url', 'pull_email', 'pull_password', 'pull_token'] as $column) {
            $this->assertEquals('encrypted', $casts[$column] ?? null, "{$column} is not an encrypted cast");
        }
    }

    // ------------------------------------------------------------- run history

    public function test_a_real_run_records_a_prunable_history_row(): void
    {
        $this->save();
        $this->fakeVendor();

        $this->artisan('hrms:pull', ['company' => 'ALPHA1'])->assertSuccessful();

        $run = HrmsPullRun::where('company_id', $this->company->id)->sole();

        $this->assertEquals('ok', $run->status);
        $this->assertEquals('cyberpulse', $run->adapter);
        $this->assertEquals(7, $run->fetched);
        $this->assertEquals(1, $run->applied);
        $this->assertEquals(2, $run->unknown_employee);
        $this->assertFalse($run->dry_run);
    }

    /**
     * Counts only. The per-leave detail would reintroduce employee identifiers
     * into a table that has no need of them.
     */
    public function test_a_run_row_carries_no_employee_data(): void
    {
        $this->save();
        $this->fakeVendor();
        $this->artisan('hrms:pull', ['company' => 'ALPHA1']);

        $raw = json_encode((array) DB::table('hrms_pull_runs')->first());

        foreach (['alice@alpha.test', 'EMP101', '66e0bb0000000000000000e1', 'Dental surgery'] as $pii) {
            $this->assertStringNotContainsString($pii, $raw);
        }
    }

    public function test_run_history_older_than_the_retention_window_is_pruned(): void
    {
        $this->save();

        $old = HrmsPullRun::create(['company_id' => $this->company->id, 'status' => 'ok']);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();

        $recent = HrmsPullRun::create(['company_id' => $this->company->id, 'status' => 'ok']);

        $this->artisan('model:prune', ['--model' => [HrmsPullRun::class]])->assertSuccessful();

        $this->assertDatabaseMissing('hrms_pull_runs', ['id' => $old->id]);
        $this->assertDatabaseHas('hrms_pull_runs', ['id' => $recent->id]);
    }

    // --------------------------------------------------------------- scheduler

    public function test_the_scheduler_runs_the_pull_on_an_interval_and_before_the_cutoff(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->getSummaryForDisplay().' @ '.$event->expression);

        $interval = (int) config('hrms.cyberpulse.pull_every_minutes');

        $this->assertTrue(
            $events->contains(fn ($e) => str_contains($e, 'hrms:pull') && str_contains($e, "*/{$interval} * * * *")),
            "no hrms:pull scheduled every {$interval} minutes: ".$events->implode(' | '),
        );

        $this->assertTrue(
            $events->contains(fn ($e) => str_contains($e, 'before-cutoff') && str_contains($e, '* * * * *')),
            'no per-minute pre-cutoff pull scheduled',
        );
    }

    public function test_the_scheduler_prunes_both_hrms_tables(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->getSummaryForDisplay());

        $prune = $events->first(fn ($e) => str_contains($e, 'model:prune'));

        $this->assertNotNull($prune);
        $this->assertStringContainsString('HrmsWebhookEvent', $prune);
        $this->assertStringContainsString('HrmsPullRun', $prune);
    }

    public function test_the_page_reads_the_props_the_controller_sends(): void
    {
        $page = file_get_contents(resource_path('js/Pages/CompanyAdmin/Hrms/Index.vue'));

        $this->assertStringContainsString('/company-admin/hrms/pull-connection', $page);
        $this->assertStringContainsString('/company-admin/hrms/pull-test', $page);
        $this->assertStringContainsString('pull_test', $page);
        $this->assertStringContainsString('has_password', $page);
        $this->assertStringContainsString('pull_test.unmatched', $page);

        $props = $this->hrmsProps();
        $this->assertArrayHasKey('pull', $props);
        $this->assertArrayHasKey('pull_test', $props);
    }
}
