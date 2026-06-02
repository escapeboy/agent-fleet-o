<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('semantic_cache_entries', function (Blueprint $table) {
            // Namespaces each cached vector by the embedding model that produced
            // it, so a future embedding-model/driver change can never compare
            // vectors across embedding spaces (meaningless cosine) or dimensions
            // (a hard pgvector error). Nullable + backfilled below so existing
            // rows keep matching the current default — zero cache invalidation.
            $table->string('embedding_model', 100)->nullable()->after('model');
            $table->index(['team_id', 'provider', 'model', 'embedding_model'], 'semantic_cache_team_provider_model_embmodel_idx');
        });

        // Backfill existing rows with the identifier that produced their
        // vectors. SemanticCache has always embedded via the default-constructed
        // EmbeddingService (openai / text-embedding-3-small), regardless of
        // config, so the historically accurate tag is this literal — and it
        // equals EmbeddingService::identifier() at runtime, so old entries keep
        // hitting after deploy.
        DB::table('semantic_cache_entries')
            ->whereNull('embedding_model')
            ->update(['embedding_model' => 'openai:text-embedding-3-small']);
    }

    public function down(): void
    {
        Schema::table('semantic_cache_entries', function (Blueprint $table) {
            $table->dropIndex('semantic_cache_team_provider_model_embmodel_idx');
            $table->dropColumn('embedding_model');
        });
    }
};
