<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_deployments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('provider', 50)->default('manual');
            $table->jsonb('config')->default('{}');
            $table->string('status')->default('pending');
            $table->timestamp('deployed_at')->nullable();
            $table->text('build_log')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_deployments');
    }
};
