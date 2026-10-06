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
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(static::TEST_NOW, static::TEST_TIMEZONE));
    }
}
