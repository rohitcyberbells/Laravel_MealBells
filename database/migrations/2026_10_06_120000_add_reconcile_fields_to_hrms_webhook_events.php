<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hrms_webhook_events', function (Blueprint $table) {
            // Counts reconciliation passes only, not the queue's own tries. A
            // failed event is abandoned once this reaches the configured cap, so
            // a permanently broken event stops consuming workers.
            $table->unsignedInteger('reconcile_attempts')->default(0)->after('error');

            // Bounds re-dispatch to one per cooldown per event, rather than one
            // per scheduler run, so a missing queue worker cannot pile up
            // thousands of duplicate jobs.
            $table->timestamp('last_reconciled_at')->nullable()->after('reconcile_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('hrms_webhook_events', function (Blueprint $table) {
            $table->dropColumn(['reconcile_attempts', 'last_reconciled_at']);
        });
    }
};
