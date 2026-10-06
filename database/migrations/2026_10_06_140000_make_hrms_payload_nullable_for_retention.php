<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retention redacts the payload rather than deleting the row, so the column
     * has to accept null while status and result stay for the audit trail.
     */
    public function up(): void
    {
        Schema::table('hrms_webhook_events', function (Blueprint $table) {
            $table->json('payload')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('hrms_webhook_events', function (Blueprint $table) {
            $table->json('payload')->nullable(false)->change();
        });
    }
};
