<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiffin services are archived, not destroyed.
 *
 * The same hazard companies had, left open when they were fixed. Five tables
 * point at tiffin_services and four of those foreign keys cascade on delete -
 * weekly_menus (and its items), daily_overrides, company_tiffin_assignments,
 * and meal_counts.
 *
 * meal_counts is the one that matters: it is the record of what the kitchen was
 * actually told to cook, and therefore the evidence in any billing dispute with
 * that vendor. Deleting the vendor deleted the proof of what they were asked
 * for - which is precisely the moment you need it.
 *
 * A soft delete fixes the cascade by never issuing a DELETE at all, so the
 * foreign keys stay quiet and every child row survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiffin_services', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tiffin_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
        });
    }
};
