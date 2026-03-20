<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->integer('retrieval_count')->default(0)->after('last_accessed_at');
            $table->string('visibility', 20)->default('private')->after('retrieval_count');
            $table->string('content_hash', 64)->nullable()->after('visibility');

            $table->index(['agent_id', 'content_hash'], 'memories_agent_hash_idx');
            $table->index(['team_id', 'visibility'], 'memories_team_visibility_idx');
            $table->index(['agent_id', 'created_at', 'importance'], 'memories_consolidation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropIndex('memories_agent_hash_idx');
            $table->dropIndex('memories_team_visibility_idx');
            $table->dropIndex('memories_consolidation_idx');

            $table->dropColumn(['retrieval_count', 'visibility', 'content_hash']);
        });
    }
};
