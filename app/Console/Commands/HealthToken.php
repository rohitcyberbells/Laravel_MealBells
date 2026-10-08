<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Prints a token for /health/ping.
 *
 * It only generates and prints - it does not write .env. Writing the file would
 * mean rewriting a production .env from a web user's process, and the value
 * still has to be deployed deliberately and config:cache rerun, so the manual
 * step is the honest one.
 */
class HealthToken extends Command
{
    protected $signature = 'mealbells:health-token';

    protected $description = 'Generate a token for the /health/ping monitoring endpoint';

    public function handle(): int
    {
        $token = Str::random(48);

        $this->newLine();
        $this->line('  Add this to .env, then rerun `php artisan config:cache`:');
        $this->newLine();
        $this->line("  HEALTH_PING_TOKEN={$token}");
        $this->newLine();
        $this->line('  Point the monitor at:');
        $this->line('  '.rtrim((string) config('app.url'), '/')."/health/ping?token={$token}");
        $this->newLine();
        $this->comment('  Until the token is set, the endpoint answers 404.');
        $this->newLine();

        return self::SUCCESS;
    }
}
