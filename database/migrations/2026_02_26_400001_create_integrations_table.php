<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('driver');
            $table->string('name');
            $table->foreignUuid('credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('disconnected');
            $table->jsonb('config')->default('{}');
            $table->jsonb('meta')->default('{}');
            $table->timestamp('last_pinged_at')->nullable();
            $table->string('last_ping_status')->nullable();
            $table->text('last_ping_message')->nullable();
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'driver']);
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
