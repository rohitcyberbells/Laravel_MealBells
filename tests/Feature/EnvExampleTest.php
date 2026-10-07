<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * .env.example is production-shaped; .env.local.example is what a developer
 * gets from `composer run setup`.
 *
 * Two files drift apart silently, and a variable that exists only in config is
 * a knob nobody knows about - so both are checked here.
 */
class EnvExampleTest extends TestCase
{
    /** @return array<string, string> */
    protected function parse(string $file): array
    {
        $out = [];

        foreach (file(base_path($file)) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $out[trim($k)] = trim($v, " \"'");
        }

        return $out;
    }

    /** @return array<int, string> */
    protected function declaredNames(string $file): array
    {
        preg_match_all('/^#?\s*([A-Z][A-Z0-9_]+)=/m', file_get_contents(base_path($file)), $m);

        return array_unique($m[1]);
    }

    public function test_both_example_files_exist(): void
    {
        $this->assertFileExists(base_path('.env.example'));
        $this->assertFileExists(base_path('.env.local.example'));
    }

    /**
     * The file someone copies onto a server must not carry a development
     * default. These four are the ones that cause real harm.
     */
    public function test_the_production_example_is_production_safe(): void
    {
        $env = $this->parse('.env.example');

        $this->assertEquals('production', $env['APP_ENV']);
        $this->assertEquals('false', $env['APP_DEBUG'], 'debug mode serves stack traces and credentials');
        $this->assertEquals('true', $env['SESSION_SECURE_COOKIE'], 'the session cookie would travel over plain http');
        $this->assertNotEquals('log', $env['MAIL_MAILER'], 'the log mailer sends nothing at all');
    }

    public function test_the_production_example_rotates_logs_and_is_not_verbose(): void
    {
        $env = $this->parse('.env.example');

        $this->assertEquals('daily', $env['LOG_CHANNEL']);
        $this->assertEquals('warning', $env['LOG_LEVEL']);
        $this->assertArrayHasKey('LOG_DAILY_DAYS', $env);
    }

    /**
     * A worker is required for anything queued, so the production example must
     * not say 'sync' - that would run jobs inside the web request.
     */
    public function test_the_production_example_uses_a_real_queue(): void
    {
        $this->assertNotEquals('sync', $this->parse('.env.example')['QUEUE_CONNECTION']);
    }

    public function test_the_local_example_is_usable_without_a_server_or_certificate(): void
    {
        $env = $this->parse('.env.local.example');

        $this->assertEquals('local', $env['APP_ENV']);
        $this->assertEquals('true', $env['APP_DEBUG']);
        $this->assertEquals('sqlite', $env['DB_CONNECTION']);
        // A secure-only cookie is simply never sent over http://localhost.
        $this->assertEquals('false', $env['SESSION_SECURE_COOKIE']);
        // No worker needed to see a notification.
        $this->assertEquals('sync', $env['QUEUE_CONNECTION']);
        $this->assertEquals('log', $env['MAIL_MAILER']);
    }

    /**
     * `composer run setup` copies the local file. Copying the production one
     * would hand a new developer pgsql with no server and debug mode off.
     */
    public function test_setup_copies_the_local_example(): void
    {
        $composer = file_get_contents(base_path('composer.json'));

        $this->assertStringContainsString(".env.local.example', '.env'", $composer);
        $this->assertStringNotContainsString(".env.example', '.env'", $composer);
    }

    /**
     * The two files must declare the same variables, or one of them quietly
     * stops being a complete picture.
     */
    public function test_the_two_examples_declare_the_same_variables(): void
    {
        $production = $this->declaredNames('.env.example');
        $local = $this->declaredNames('.env.local.example');

        $this->assertEmpty(
            array_diff($production, $local),
            'in .env.example but not .env.local.example: '.implode(', ', array_diff($production, $local)),
        );
        $this->assertEmpty(
            array_diff($local, $production),
            'in .env.local.example but not .env.example: '.implode(', ', array_diff($local, $production)),
        );
    }

    /**
     * Every knob this application invented is listed, commented or not. A
     * variable that exists only in config/ is one an operator cannot discover.
     */
    public function test_every_app_specific_variable_is_documented(): void
    {
        $used = [];

        foreach (glob(config_path('*.php')) as $file) {
            preg_match_all("/env\('([A-Z][A-Z0-9_]+)'/", file_get_contents($file), $m);
            $used = array_merge($used, $m[1]);
        }

        $ours = array_values(array_filter(
            array_unique($used),
            fn (string $name) => str_starts_with($name, 'HRMS_') || str_starts_with($name, 'MEALBELLS_'),
        ));

        $this->assertNotEmpty($ours, 'no app-specific env vars found at all');

        $declared = $this->declaredNames('.env.example');

        $this->assertEmpty(
            array_diff($ours, $declared),
            'used in config/ but absent from .env.example: '.implode(', ', array_diff($ours, $declared)),
        );
    }

    /**
     * The engine knobs are tunable, but the defaults must not have moved - a
     * deployment reading this file should get the behaviour that was tested.
     */
    public function test_the_engine_defaults_are_unchanged(): void
    {
        $this->assertEquals(60, config('mealbells.advance_limit_days'));
        $this->assertEquals(100, config('mealbells.max_extra_meals'));
        $this->assertEquals('Asia/Kolkata', config('mealbells.default_timezone'));
        $this->assertEquals(20, config('mealbells.anomaly.deviation_threshold_percent'));
        $this->assertEquals(3, config('mealbells.anomaly.history_min_days'));
        $this->assertEquals(10, config('mealbells.anomaly.max_extra_spike'));
        $this->assertEquals(0.30, config('mealbells.anomaly.max_skip_ratio'));
    }

    /**
     * Which sources count as automated decides who may override whom, so they
     * are deliberately not env-tunable.
     */
    public function test_the_source_rules_are_not_env_tunable(): void
    {
        $config = file_get_contents(config_path('mealbells.php'));

        $this->assertMatchesRegularExpression("/'auto_skip_sources' => \['leave', 'wfh', 'recurring'\]/", $config);
        $this->assertMatchesRegularExpression("/'manual_skip_sources' => \['hr', 'self'\]/", $config);
    }
}
