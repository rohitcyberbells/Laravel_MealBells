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
        Schema::create('meal_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('tiffin_service_id')->constrained('tiffin_services')->cascadeOnDelete();
            $table->date('date');
            $table->integer('base_eligible_count');
            $table->integer('skip_count');
            $table->integer('extra_count');
            $table->integer('final_expected_count');
            $table->json('breakdown'); // Source-wise skip breakdown e.g. {"leave": 2, "wfh": 1}
            $table->string('status')->default('draft'); // 'draft', 'confirmed', 'auto_confirmed'
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            // Unique constraint
            $table->unique(['company_id', 'date']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meal_counts');
    }
};
