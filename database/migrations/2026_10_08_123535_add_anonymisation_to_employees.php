<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a person's details were removed on request, and who did it.
 *
 * Recorded on the row because the row is all that is left: after
 * anonymisation nothing else identifies the request, so without this there is
 * no way to tell a removed employee from one whose name was never filled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('anonymised_at')->nullable()->after('external_id');
            $table->foreignId('anonymised_by')->nullable()->after('anonymised_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('anonymised_by');
            $table->dropColumn('anonymised_at');
        });
    }
};
