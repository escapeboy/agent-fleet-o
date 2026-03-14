<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('git_pull_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('git_repository_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('approval_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('branch');
            $table->string('base_branch')->default('main');
            $table->string('pr_number')->nullable();
            $table->text('pr_url')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('merged_at')->nullable();
            $table->timestamps();

            $table->index(['git_repository_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_pull_requests');
    }
};
