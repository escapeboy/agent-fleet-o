<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_identities', function (Blueprint $table) {
            $table->float('health_score')->nullable()->after('risk_evaluated_at');
            $table->float('health_recency_score')->nullable()->after('health_score');
            $table->float('health_frequency_score')->nullable()->after('health_recency_score');
            $table->float('health_sentiment_score')->nullable()->after('health_frequency_score');
            $table->timestamp('health_scored_at')->nullable()->after('health_sentiment_score');

            $table->index('health_score');
        });
    }

    public function down(): void
    {
        Schema::table('contact_identities', function (Blueprint $table) {
            $table->dropIndex(['health_score']);
            $table->dropColumn([
                'health_score',
                'health_recency_score',
                'health_frequency_score',
                'health_sentiment_score',
                'health_scored_at',
            ]);
        });
    }
};
