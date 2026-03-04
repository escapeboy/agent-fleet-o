<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_kms_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider'); // aws_kms, gcp_kms, azure_key_vault
            $table->text('credentials'); // encrypted:array (APP_KEY) — KMS access config
            $table->text('wrapped_dek'); // KMS-encrypted DEK ciphertext, base64
            $table->string('key_identifier'); // Key ARN / GCP resource name / Azure vault+key URL
            $table->string('external_id')->nullable(); // AWS only, auto-generated
            $table->string('status')->default('testing'); // active, testing, error, disabled
            $table->timestamp('dek_wrapped_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('estimated_monthly_calls')->default(0);
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_kms_configs');
    }
};
