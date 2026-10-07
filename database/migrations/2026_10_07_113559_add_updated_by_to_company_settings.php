<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who last changed a company's meal settings.
 *
 * Every other table that affects a count records its actor - skips carry
 * created_by and cancelled_by, meal_counts carry confirmed_by and reviewed_by.
 * The settings row did not, and it is the highest-leverage record there is:
 * moving the cutoff or dropping a meal day changes every future count for the
 * whole company, and there was no way afterwards to say who did it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->foreignId('updated_by')->nullable()->after('meal_days')
                ->constrained('users')->nullOnDelete();

            // Separate from updated_at, which also moves when nothing a person
            // did was involved.
            $table->timestamp('settings_changed_at')->nullable()->after('updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn('settings_changed_at');
        });
    }
};
