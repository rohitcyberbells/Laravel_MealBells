<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who cancelled a skip: a person, or an integration releasing its own.
 *
 * cancelled_by is always a real user - an integration acts as the company's
 * admin for the audit trail - so it cannot tell the two apart. That mattered
 * once a leave could be withdrawn and re-approved: RecordSkip refuses to let an
 * automated source resurrect a cancelled skip, which is exactly right when a
 * person cancelled it and exactly wrong when the HRMS is restoring a withdrawal
 * it made itself.
 *
 * Null means a person did it, so every skip cancelled before this column existed
 * keeps the stronger protection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skips', function (Blueprint $table) {
            $table->string('cancelled_source')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('skips', function (Blueprint $table) {
            $table->dropColumn('cancelled_source');
        });
    }
};
