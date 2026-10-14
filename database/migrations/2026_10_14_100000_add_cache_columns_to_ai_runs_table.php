<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UsageTracking writes cached_input_tokens and cache_strategy on every LLM call,
 * but until now only a private migration created them. Guarded per column so
 * installs that already have them (that migration, or a manual ALTER) are skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_runs', 'cached_input_tokens')) {
                $table->integer('cached_input_tokens')->default(0);
            }
            if (! Schema::hasColumn('ai_runs', 'cache_strategy')) {
                $table->string('cache_strategy', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            foreach (['cache_strategy', 'cached_input_tokens'] as $column) {
                if (Schema::hasColumn('ai_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
