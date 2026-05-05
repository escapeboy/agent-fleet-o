<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            // Document boost: user feedback signal (0 = neutral, positive = upvoted, negative = downvoted).
            // Multiplied into composite retrieval score as a quality flywheel.
            $table->integer('boost')->default(0)->after('source_url');
            // Contextual RAG: LLM-generated 64-token context situating the chunk within its document.
            // Prepended to chunk content before re-embedding at index time; stripped at retrieval.
            $table->text('chunk_context')->nullable()->after('boost');
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropColumn(['boost', 'chunk_context']);
        });
    }
};
