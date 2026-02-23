<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add source_native_id to signals for provider-level deduplication.
 *
 * content_hash deduplication prevents exact payload duplicates but fails when
 * the same alert fires a second webhook with minor metadata changes (e.g. Sentry
 * updating an issue's occurrence count). source_native_id stores the stable
 * provider-assigned ID (e.g. "sentry:ISSUE-123", "dd:12345678", "pd:Q2AIUWIU") so
 * that repeated webhooks for the same upstream event are merged into one signal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->string('source_native_id')->nullable()->after('source_identifier');
            $table->index(['source_type', 'source_native_id'], 'signals_source_native_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropIndex('signals_source_native_id_idx');
            $table->dropColumn('source_native_id');
        });
    }
};
