<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_hrms_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();

            // Encrypted by the model's cast, so this holds ciphertext and needs
            // far more room than the secret itself.
            $table->text('webhook_secret')->nullable();

            // 'signature' | 'token'. Null falls back to the config default, so a
            // row can carry only a secret.
            $table->string('auth')->nullable();

            $table->timestamp('secret_rotated_at')->nullable();
            $table->foreignId('rotated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_hrms_connections');
    }
};
