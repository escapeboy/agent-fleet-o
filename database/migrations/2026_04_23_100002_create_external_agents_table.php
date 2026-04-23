<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_agents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->text('endpoint_url');
            $table->text('manifest_url')->nullable();
            $table->jsonb('manifest_cached')->nullable();
            $table->timestampTz('manifest_fetched_at')->nullable();
            $table->string('protocol_version', 32)->default('asi1-v1');
            $table->foreignUuid('credential_id')->nullable()->constrained('credentials')->nullOnDelete();
            $table->string('status', 24)->default('active');
            $table->timestampTz('last_call_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('capabilities')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->unique(['team_id', 'slug']);
            $table->index('status');
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_agents');
    }
};
