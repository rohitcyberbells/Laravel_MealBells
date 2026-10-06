<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * All three HRMS schema changes land together so later steps never need a
     * second migration mid-feature.
     */
    public function up(): void
    {
        Schema::create('hrms_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // The vendor's own event id. Unique PER COMPANY, never globally: two
            // HRMS tenants can legitimately both emit 'evt_1', and a global
            // unique would swallow the second as a duplicate and lose the leave.
            $table->string('external_event_id');
            $table->string('event_type');

            // Which leave this event concerns, so a cancellation can find the
            // skips the approval produced.
            $table->string('leave_external_id')->nullable();

            // When the VENDOR says it happened. Used to discard an event that
            // arrives behind a newer one for the same leave, because webhook
            // delivery is not ordered.
            $table->timestamp('occurred_at')->nullable();

            $table->json('payload');

            // received | applied | blocked | failed
            //   blocked = permanently unactionable (unknown employee, past cutoff)
            //   failed  = transient, worth retrying
            // 'duplicate' is never stored: the unique index below rejects the
            // insert, so a repeat delivery is answered without a row.
            $table->string('status')->default('received');

            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'external_event_id']);
            $table->index(['company_id', 'leave_external_id']);
            $table->index('status');
        });

        Schema::table('employees', function (Blueprint $table) {
            // HRMS primary key for this person. Nullable so manual and CSV
            // employees stay valid, and scoped per company to match the existing
            // unique(company_id, employee_code).
            $table->string('external_id')->nullable()->after('employee_code');
            $table->unique(['company_id', 'external_id']);
        });

        Schema::table('skips', function (Blueprint $table) {
            // Provenance. Set only when a skip is created from an HRMS event, so
            // a null value marks the row as human-entered - which is what keeps
            // the webhook from ever cancelling HR's own work.
            $table->string('external_ref')->nullable()->after('source');
            $table->index(['company_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('skips', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'external_ref']);
            $table->dropColumn('external_ref');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'external_id']);
            $table->dropColumn('external_id');
        });

        Schema::dropIfExists('hrms_webhook_events');
    }
};
