<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->float('relevance_score')->nullable()->after('score');
            $table->timestamp('relevance_scored_at')->nullable()->after('relevance_score');
            $table->index('relevance_score');
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropIndex(['relevance_score']);
            $table->dropColumn(['relevance_score', 'relevance_scored_at']);
        });
    }
};
