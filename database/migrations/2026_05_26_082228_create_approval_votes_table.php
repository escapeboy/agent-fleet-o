<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_votes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('decision'); // approve | reject
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            // One vote per user per request — repeat votes upsert, not stack.
            $table->unique(['approval_request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_votes');
    }
};
