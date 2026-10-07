<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Companies are archived, not destroyed.
 *
 * Deleting one cascaded across eleven tables - employees, skips,
 * meal_adjustments, calendar days, HRMS events, and meal_counts, which is the
 * record of what the kitchen was actually told and therefore the evidence in
 * any billing dispute. One click, irreversible, with no backup in place.
 *
 * A soft delete fixes the cascade by never issuing a DELETE at all, so the
 * foreign keys stay quiet and every child row survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
        });
    }
};
