<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('outbound_proposal_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued'); // queued|sending|sent|failed|bounced
            $table->string('external_id')->nullable();
            $table->jsonb('response')->nullable();
            $table->integer('retry_count')->default(0);
            $table->string('idempotency_key')->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_actions');
    }
};
