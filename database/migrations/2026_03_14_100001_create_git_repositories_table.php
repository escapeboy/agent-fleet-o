<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('git_repositories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('url');
            $table->string('provider')->default('generic');
            $table->string('mode')->default('api_only');
            $table->string('default_branch')->default('main');
            $table->jsonb('config')->default('{}');
            $table->string('status')->default('active');
            $table->timestamp('last_ping_at')->nullable();
            $table->string('last_ping_status')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_repositories');
    }
};
