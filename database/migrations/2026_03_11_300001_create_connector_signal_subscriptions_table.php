<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_signal_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('name');
            $table->string('driver');
            $table->jsonb('filter_config')->default('{}');
            $table->boolean('is_active')->default(true);
            // Per-subscription HMAC secret (encrypted with team DEK).
            // Registered at the provider so the provider can sign its outbound POSTs.
            $table->text('webhook_secret')->nullable();
            // Provider-assigned webhook record ID — stored so we can deregister cleanly.
            $table->string('webhook_id')->nullable();
            $table->string('webhook_status')->nullable();   // registered | failed | expired
            $table->timestamp('webhook_expires_at')->nullable(); // Jira: 30-day TTL
            $table->timestamp('last_signal_at')->nullable();
            $table->unsignedInteger('signal_count')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'driver', 'is_active']);
            $table->index('integration_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_signal_subscriptions');
    }
};
