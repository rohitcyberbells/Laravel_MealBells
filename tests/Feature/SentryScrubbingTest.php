<?php

namespace Tests\Feature;

use App\Observability\SentryScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventId;
use Sentry\UserDataBag;
use Tests\TestCase;

/**
 * What may and may not leave this application for a third party.
 *
 * Sentry is optional, but when it is on it receives, by default, the request
 * body that caused the error. In MealBells that body is sometimes a sign-in
 * form holding a password, sometimes an HRMS payload holding a whole company's
 * leave with employee names and codes, and sometimes an HRMS credential being
 * saved. send_default_pii=false does not cover any of it: the payload is in the
 * request data, not in the PII fields.
 *
 * These are the tests that make enabling it defensible.
 */
class SentryScrubbingTest extends TestCase
{
    protected function scrub(Event $event): Event
    {
        $result = SentryScrubber::scrub($event);

        $this->assertNotNull($result);

        return $result;
    }

    protected function event(): Event
    {
        return Event::createEvent(EventId::generate());
    }

    public function test_a_password_in_a_form_body_never_leaves(): void
    {
        $event = $this->event();
        $event->setRequest([
            'url' => 'https://mealbells.test/login',
            'data' => ['identifier' => 'hr@acme.test', 'password' => 'correct horse battery'],
        ]);

        $request = $this->scrub($event)->getRequest();

        $this->assertSame('[redacted]', $request['data']['password']);
        $this->assertStringNotContainsString('correct horse battery', json_encode($request));
    }

    /**
     * The one that matters most. A single HRMS webhook body holds a company's
     * leave for the day: every affected employee by name and code.
     */
    public function test_an_hrms_payload_never_leaves(): void
    {
        $event = $this->event();
        $event->setRequest([
            'data' => [
                'event_type' => 'leave.approved',
                'payload' => [
                    'employee_code' => 'ACME001',
                    'employee_name' => 'Alice Secretname',
                    'reason' => 'Medical',
                ],
            ],
        ]);

        $request = $this->scrub($event)->getRequest();

        $this->assertSame('[redacted]', $request['data']['payload']);
        $this->assertStringNotContainsString('Alice Secretname', json_encode($request));
        $this->assertStringNotContainsString('ACME001', json_encode($request));
    }

    /**
     * The HRMS body arrives raw, so it cannot be scrubbed key by key - there
     * are no keys yet.
     */
    public function test_a_raw_request_body_is_dropped_whole(): void
    {
        $event = $this->event();
        $event->setRequest(['data' => '{"employee_code":"ACME001","name":"Alice Secretname"}']);

        $this->assertSame('[redacted]', $this->scrub($event)->getRequest()['data']);
    }

    public function test_credential_headers_and_cookies_are_dropped(): void
    {
        $event = $this->event();
        $event->setRequest([
            'headers' => [
                'Authorization' => 'Bearer secret-token',
                'X-Mealbells-Signature' => 'abc123',
                'X-Health-Token' => 'monitor-secret',
                'Accept' => 'application/json',
            ],
            'cookies' => ['mealbells_session' => 'a-live-session-id'],
            'env' => ['DB_PASSWORD' => 'hunter2'],
        ]);

        $request = $this->scrub($event)->getRequest();

        $this->assertSame('[redacted]', $request['headers']['Authorization']);
        $this->assertSame('[redacted]', $request['headers']['X-Mealbells-Signature']);
        $this->assertSame('[redacted]', $request['headers']['X-Health-Token']);
        // Kept: it is useful and identifies nobody.
        $this->assertSame('application/json', $request['headers']['Accept']);

        // A session cookie in an error report is a usable credential for as
        // long as the report is readable.
        $this->assertArrayNotHasKey('cookies', $request);
        $this->assertArrayNotHasKey('env', $request);
    }

    public function test_a_token_in_a_url_is_stripped(): void
    {
        $event = $this->event();
        $event->setRequest([
            'url' => 'https://mealbells.test/health/ping?token=the-monitor-secret',
            'query_string' => 'token=the-monitor-secret&date=2026-10-05',
        ]);

        $request = $this->scrub($event)->getRequest();

        $this->assertStringNotContainsString('the-monitor-secret', $request['url']);
        $this->assertStringNotContainsString('the-monitor-secret', $request['query_string']);
        // The useful part survives.
        $this->assertStringContainsString('date=2026-10-05', $request['query_string']);
    }

    /**
     * Addresses turn up in exception messages and log lines, which no key-based
     * rule can catch.
     */
    public function test_an_address_in_free_text_is_masked_but_the_domain_is_kept(): void
    {
        $event = $this->event();
        $event->setExtra(['note' => 'Delivery to alice.secretname@acme.test failed']);

        $extra = $this->scrub($event)->getExtra();

        $this->assertStringNotContainsString('alice.secretname', $extra['note']);
        // The domain is often the useful part of a mail failure, and names
        // nobody.
        $this->assertStringContainsString('@acme.test', $extra['note']);
    }

    /**
     * The id makes the report actionable without saying who: anyone who needs
     * the name looks it up in MealBells, where that access is controlled.
     */
    public function test_the_user_keeps_an_id_and_a_role_and_nothing_else(): void
    {
        $event = $this->event();

        $user = UserDataBag::createFromUserIdentifier(42);
        $user->setEmail('hr@acme.test');
        $user->setUsername('Acme HR');
        $user->setIpAddress('203.0.113.9');
        $user->setMetadata('role', 'company_admin');
        $user->setMetadata('login_code', 'ACME001');
        $event->setUser($user);

        $scrubbed = $this->scrub($event)->getUser();

        $this->assertSame(42, $scrubbed->getId());
        $this->assertNull($scrubbed->getEmail());
        $this->assertNull($scrubbed->getUsername());
        $this->assertNull($scrubbed->getIpAddress());
        $this->assertSame('company_admin', $scrubbed->getMetadata()['role']);
        $this->assertArrayNotHasKey('login_code', $scrubbed->getMetadata());
    }

    public function test_breadcrumbs_are_scrubbed_too(): void
    {
        $event = $this->event();
        $event->setBreadcrumb([
            new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'query',
                'select * from users where email = alice@acme.test',
                ['password' => 'hunter2', 'bindings' => ['token' => 'abc']],
            ),
        ]);

        $crumb = $this->scrub($event)->getBreadcrumbs()[0];

        $this->assertStringNotContainsString('alice@', $crumb->getMessage());
        $this->assertSame('[redacted]', $crumb->getMetadata()['password']);
        $this->assertSame('[redacted]', $crumb->getMetadata()['bindings']['token']);
    }

    public function test_nesting_does_not_hide_a_secret(): void
    {
        $event = $this->event();
        $event->setExtra([
            'connection' => [
                'settings' => [
                    'deep' => ['pull_password' => 'hunter2', 'base_url' => 'https://hrms.test'],
                ],
            ],
        ]);

        $extra = $this->scrub($event)->getExtra();

        $this->assertSame('[redacted]', $extra['connection']['settings']['deep']['pull_password']);
        $this->assertSame('https://hrms.test', $extra['connection']['settings']['deep']['base_url']);
    }

    public function test_the_event_is_tagged_with_the_environment(): void
    {
        $this->assertSame(
            config('app.env'),
            $this->scrub($this->event())->getTags()['mealbells.environment'],
        );
    }

    public function test_a_configured_release_is_attached(): void
    {
        config()->set('sentry.release', 'mealbells@2026.10.08-abc1234');

        $this->assertSame(
            'mealbells@2026.10.08-abc1234',
            $this->scrub($this->event())->getRelease(),
        );
    }

    /**
     * Without a DSN the SDK initialises nothing and makes no network calls, so
     * an installation that does not want a third party seeing its errors is
     * unaffected by this being installed.
     */
    public function test_there_is_no_dsn_by_default(): void
    {
        $this->assertNull(config('sentry.dsn'));
    }

    /**
     * Guarded as config rather than left to a default, because both would send
     * the actual values - an address, an employee code, a leave reason - while
     * the query shape is what is useful for debugging.
     */
    public function test_sql_bindings_are_never_captured(): void
    {
        $this->assertFalse(config('sentry.breadcrumbs.sql_bindings'));
        $this->assertFalse(config('sentry.tracing.sql_bindings'));
        $this->assertFalse(config('sentry.send_default_pii'));
    }

    /**
     * A closure here cannot be exported, so config:cache would fail on deploy -
     * after the build had already replaced the release folder.
     */
    public function test_the_scrubber_is_wired_as_a_cacheable_callable(): void
    {
        foreach (['sentry.before_send', 'sentry.before_send_transaction'] as $key) {
            $callable = config($key);

            $this->assertIsArray($callable, $key.' must not be a closure');
            $this->assertTrue(is_callable($callable), $key.' is not callable');
            $this->assertSame(SentryScrubber::class, $callable[0]);
        }
    }

    /**
     * The monitoring endpoints are polled every minute; tracing them would be
     * most of the quota and tells nobody anything.
     */
    public function test_the_monitoring_endpoints_are_not_traced(): void
    {
        $this->assertContains('/up', config('sentry.ignore_transactions'));
        $this->assertContains('/health/ping', config('sentry.ignore_transactions'));
    }
}
