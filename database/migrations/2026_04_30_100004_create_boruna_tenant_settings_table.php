<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boruna_tenant_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->unique();
            $table->boolean('enabled')->default(true);
            $table->boolean('shadow_mode')->default(true);
            $table->jsonb('workflows_enabled')->nullable();
            $table->uuid('kek_credential_id')->nullable();
            $table->integer('retention_days')->default(90);
            $table->integer('quota_per_month')->nullable();
            $table->integer('runs_this_period')->default(0);
            $table->timestamp('runs_period_reset_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boruna_tenant_settings');
    }
};
