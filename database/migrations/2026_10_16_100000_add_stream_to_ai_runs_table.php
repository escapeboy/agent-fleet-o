<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the provider call was a real stream. Usage from a stream is normalised
 * differently (UsageNormalizer::fromPrism), so cost audits need to tell them apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_runs', 'stream')) {
                $table->boolean('stream')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            if (Schema::hasColumn('ai_runs', 'stream')) {
                $table->dropColumn('stream');
            }
        });
    }
};
