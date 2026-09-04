<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('role')->default('company_admin'); // super_admin | company_admin | tiffin_admin
        $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
        $table->foreignId('tiffin_service_id')->nullable()->constrained('tiffin_services')->nullOnDelete();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropForeign(['company_id']);
        $table->dropForeign(['tiffin_service_id']);
        $table->dropColumn(['role', 'company_id', 'tiffin_service_id']);
    });
}
};
