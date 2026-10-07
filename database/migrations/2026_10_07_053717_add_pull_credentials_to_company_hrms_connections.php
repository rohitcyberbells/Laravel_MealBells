<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials and run state for a pull integration.
 *
 * A vendor with no webhooks has to be polled, which means holding a login for
 * it. The three credential columns are encrypted at rest by the model cast, as
 * webhook_secret already is: a leaked dump must not hand over the ability to
 * sign in to a customer's HR system.
 *
 * pull_token caches the vendor's session token so a run does not log in every
 * time. It is encrypted for the same reason - it is a bearer credential - and
 * pull_token_expires_at lets a run tell "no token yet" from "token we hold".
 *
 * The last_pull_* columns are the audit trail a pull has instead of a webhook's
 * per-event row: whether the last run succeeded, what it did, and why it
 * refused to act if it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_hrms_connections', function (Blueprint $table) {
            $table->text('pull_base_url')->nullable()->after('auth');
            $table->text('pull_email')->nullable()->after('pull_base_url');
            $table->text('pull_password')->nullable()->after('pull_email');
            $table->text('pull_token')->nullable()->after('pull_password');
            $table->timestamp('pull_token_expires_at')->nullable()->after('pull_token');

            // Which adapter drives the pull. Null means this company is
            // webhook-only, so a scheduled pull passes it over.
            $table->string('pull_adapter')->nullable()->after('pull_token_expires_at');

            $table->timestamp('last_pull_at')->nullable()->after('pull_adapter');

            // 'ok' | 'suspicious' | 'failed'. 'suspicious' is the important one:
            // the run completed but a safety guard stopped it cancelling, so the
            // data is stale rather than wrong and a human should look.
            $table->string('last_pull_status')->nullable()->after('last_pull_at');
            $table->json('last_pull_summary')->nullable()->after('last_pull_status');
            $table->text('last_pull_error')->nullable()->after('last_pull_summary');

            // Enforces the self-imposed one-login-per-minute cap across runs, so
            // a crash loop cannot hammer the vendor's login endpoint.
            $table->timestamp('last_login_at')->nullable()->after('last_pull_error');
        });
    }

    public function down(): void
    {
        Schema::table('company_hrms_connections', function (Blueprint $table) {
            $table->dropColumn([
                'pull_base_url',
                'pull_email',
                'pull_password',
                'pull_token',
                'pull_token_expires_at',
                'pull_adapter',
                'last_pull_at',
                'last_pull_status',
                'last_pull_summary',
                'last_pull_error',
                'last_login_at',
            ]);
        });
    }
};
