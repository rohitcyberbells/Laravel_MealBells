<?php

namespace Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Monday 2026-10-05 at 08:00 IST: a meal day, and earlier than every cutoff
     * the suite configures (10:30 / 11:00 / 12:00).
     *
     * The suite carries hundreds of hardcoded 2026-10-xx dates. Without a fixed
     * clock those dates drift into the past as real time passes, and guards such
     * as past_date begin firing ahead of the rule a test is actually asserting.
     * Tests that need a different moment still call Carbon::setTestNow()
     * themselves, which overrides this.
     */
    protected const TEST_NOW = '2026-10-05 08:00:00';

    protected const TEST_TIMEZONE = 'Asia/Kolkata';

    protected function setUp(): void
    {
        $this->refuseToRunAgainstACachedConfig();

        parent::setUp();

        Carbon::setTestNow(Carbon::parse(static::TEST_NOW, static::TEST_TIMEZONE));
    }

    /**
     * Refuse to run at all while bootstrap/cache/config.php exists.
     *
     * phpunit.xml pins the suite to sqlite in memory through <env> entries, and
     * those cannot override a cached config - the cached file is read instead.
     * So on any machine where `php artisan config:cache` has been run, the whole
     * suite silently points at whatever database that config names, and
     * RefreshDatabase is willing to migrate:fresh it.
     *
     * That is a production database on a server, and it happened here during a
     * production-mode rehearsal: the suite ran against the rehearsal PostgreSQL
     * database instead of :memory:. Nothing was lost that time, which is luck,
     * not a reason to leave it.
     */
    protected function refuseToRunAgainstACachedConfig(): void
    {
        // dirname(__DIR__), not base_path(): this runs before parent::setUp()
        // boots the application, so no helper is available yet.
        if (! file_exists(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            return;
        }

        $this->fail(
            'bootstrap/cache/config.php exists, so phpunit.xml cannot pin the test database '
            .'and this suite would run against whatever that config names. '
            .'Run `php artisan config:clear` first.'
        );
    }
}
