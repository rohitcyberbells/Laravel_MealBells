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
        Schema::create('company_tiffin_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('tiffin_service_id')->constrained('tiffin_services')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('unassigned_at')->nullable();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_tiffin_assignments');
    }
};
