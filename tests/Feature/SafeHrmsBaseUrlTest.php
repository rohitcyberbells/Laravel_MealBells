<?php

namespace Tests\Feature;

use App\Rules\SafeHrmsBaseUrl;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The pull's base URL is supplied by a company admin and the server then signs
 * in to it, so the rule carries two jobs: keep the password off plain http, and
 * refuse to be pointed at the private network it sits inside.
 */
class SafeHrmsBaseUrlTest extends TestCase
{
    protected function fails(string $url): bool
    {
        return Validator::make(['u' => $url], ['u' => [new SafeHrmsBaseUrl]])->fails();
    }

    protected function errorFor(string $url): string
    {
        return Validator::make(['u' => $url], ['u' => [new SafeHrmsBaseUrl]])
            ->errors()->first('u');
    }

    // ------------------------------------------------------------- production

    /** @return array<int, array<int, string>> */
    public static function privateAddresses(): array
    {
        return [
            ['https://localhost/api'],
            ['https://localhost:3000/api'],
            ['https://127.0.0.1/api'],
            ['https://127.1.2.3/api'],
            ['https://10.0.0.5/api'],
            ['https://10.255.255.254/api'],
            ['https://192.168.1.10/api'],
            // On a cloud host this is the instance metadata endpoint - the worst
            // thing an SSRF can reach.
            ['https://169.254.169.254/latest/meta-data/'],
            // The third RFC 1918 block, which the brief did not name.
            ['https://172.16.0.1/api'],
            ['https://172.31.255.255/api'],
            ['https://0.0.0.0/api'],
            ['https://[::1]/api'],
            ['https://[fd00::1]/api'],
            ['https://[fe80::1]/api'],
        ];
    }

    #[DataProvider('privateAddresses')]
    public function test_a_private_or_internal_address_is_refused_in_production(string $url): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->assertTrue($this->fails($url), "{$url} was accepted");
        $this->assertStringContainsString('private or internal', $this->errorFor($url));
    }

    public function test_plain_http_is_refused_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->assertTrue($this->fails('http://hrms.example.com/api'));
        $this->assertStringContainsString('https', $this->errorFor('http://hrms.example.com/api'));
    }

    public function test_plain_http_to_localhost_is_refused_in_production_too(): void
    {
        app()->detectEnvironment(fn () => 'production');

        // The local exemption must not survive into production.
        $this->assertTrue($this->fails('http://localhost:8901/api'));
        $this->assertTrue($this->fails('http://127.0.0.1:8901/api'));
    }

    public function test_a_public_https_host_is_accepted_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->assertFalse($this->fails('https://hrms.cyberpulse.com'));
        $this->assertFalse($this->fails('https://hrms.cyberpulse.com/api/v2'));
        $this->assertFalse($this->fails('https://203.0.113.10/api'));
    }

    /**
     * 172.15 and 172.32 sit just outside RFC 1918, so the range must not be
     * matched by a loose "172." prefix.
     */
    public function test_addresses_just_outside_the_private_range_are_allowed(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->assertFalse($this->fails('https://172.15.0.1/api'));
        $this->assertFalse($this->fails('https://172.32.0.1/api'));
        // And a public host that merely starts with the same digits.
        $this->assertFalse($this->fails('https://10.example.com/api'));
    }

    // ------------------------------------------------------- local developmen

    public function test_localhost_over_http_is_allowed_locally(): void
    {
        app()->detectEnvironment(fn () => 'local');

        $this->assertFalse($this->fails('http://localhost:8901/api'));
        $this->assertFalse($this->fails('http://127.0.0.1:8901/api'));
    }

    public function test_localhost_is_allowed_in_testing_too(): void
    {
        app()->detectEnvironment(fn () => 'testing');

        $this->assertFalse($this->fails('http://localhost:8901'));
        $this->assertFalse($this->fails('http://127.0.0.1:8901'));
    }

    /**
     * The exemption is for a developer's own stub, not for the private network:
     * a RFC 1918 address is still refused locally.
     */
    public function test_the_local_exemption_does_not_extend_to_the_private_network(): void
    {
        app()->detectEnvironment(fn () => 'local');

        $this->assertTrue($this->fails('http://10.0.0.5/api'));
        $this->assertTrue($this->fails('https://169.254.169.254/'));
        $this->assertTrue($this->fails('https://192.168.1.10/api'));
    }

    // ---------------------------------------------------------------- malformed

    public function test_something_that_is_not_a_url_is_refused(): void
    {
        app()->detectEnvironment(fn () => 'production');

        foreach (['', 'not a url', 'hrms.example.com', '/api/leave', 'javascript:alert(1)'] as $bad) {
            $this->assertTrue($this->fails($bad), "'{$bad}' was accepted");
        }
    }

    public function test_a_non_http_scheme_is_refused(): void
    {
        app()->detectEnvironment(fn () => 'production');

        foreach (['ftp://hrms.example.com', 'file:///etc/passwd', 'gopher://hrms.example.com'] as $bad) {
            $this->assertTrue($this->fails($bad), "'{$bad}' was accepted");
        }
    }

    public function test_case_and_padding_do_not_get_round_the_check(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->assertTrue($this->fails('HTTPS://LOCALHOST/api'));
        $this->assertTrue($this->fails('  https://10.0.0.1/api  '));
        $this->assertTrue($this->fails('HtTp://hrms.example.com'));
    }
}
