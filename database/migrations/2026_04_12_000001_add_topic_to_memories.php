<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->string('topic', 100)->nullable()->after('category');

            // Namespace pre-filter index: narrows vector scan to agent+category+topic
            $table->index(['agent_id', 'category', 'topic'], 'memories_topic_namespace_idx');

            // Team-scoped topic index: for assistant panel searches
            $table->index(['team_id', 'category', 'topic'], 'memories_team_topic_idx');
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropIndex('memories_topic_namespace_idx');
            $table->dropIndex('memories_team_topic_idx');
            $table->dropColumn('topic');
        });
    }
};
