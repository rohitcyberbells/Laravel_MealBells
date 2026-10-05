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
        Schema::table('meal_counts', function (Blueprint $table) {
            $table->timestamp('summary_sent_at')->nullable()->after('locked_at');
            $table->timestamp('escalation_sent_at')->nullable()->after('summary_sent_at');
            $table->foreignId('reviewed_by')->nullable()->after('escalation_sent_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->json('anomaly_flags')->nullable()->after('reviewed_at');
            $table->string('lock_type')->nullable()->after('anomaly_flags'); // 'auto' | 'manual'
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meal_counts', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn([
                'summary_sent_at',
                'escalation_sent_at',
                'reviewed_by',
                'reviewed_at',
                'anomaly_flags',
                'lock_type',
            ]);
        });
    }
};
