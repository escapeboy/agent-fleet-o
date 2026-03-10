<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-team signal connector settings.
 *
 * Replaces the .env-based webhook secret model with one row per team per driver.
 * Each team gets a unique webhook URL (POST /api/signals/{driver}/{team_id}) and
 * an encrypted signing secret stored here, validated on every inbound webhook.
 *
 * secret_rotated_at / previous_webhook_secret enable a 1-hour grace period during
 * which both the old and new secret are accepted — allowing zero-downtime rotation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_connector_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();

            // The webhook connector driver (e.g. 'github', 'slack', 'webhook')
            $table->string('driver', 50);

            // Per-team signing secret — encrypted with TeamEncryptedString (XSalsa20-Poly1305)
            $table->text('webhook_secret')->nullable();
            $table->text('previous_webhook_secret')->nullable();
            $table->timestampTz('secret_rotated_at')->nullable();

            // Signal activity tracking (denormalised for fast status display)
            $table->timestampTz('last_signal_at')->nullable();
            $table->unsignedInteger('signal_count')->default(0);

            $table->boolean('is_active')->default(true);

            // Future-use: per-connector metadata (custom headers, event filters, etc.)
            $table->jsonb('metadata')->default('{}');

            $table->timestampsTz();
        });

        // One row per team per driver
        DB::statement('
            CREATE UNIQUE INDEX idx_signal_connector_settings_team_driver
            ON signal_connector_settings (team_id, driver)
        ');

        // Fast status lookups for the connector grid
        DB::statement('
            CREATE INDEX idx_signal_connector_settings_active
            ON signal_connector_settings (team_id, driver)
            WHERE is_active = true
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_connector_settings');
    }
};
