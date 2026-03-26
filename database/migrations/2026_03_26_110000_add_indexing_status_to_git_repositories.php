<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('git_repositories', function (Blueprint $table) {
            // Tracks whether the repository's code has been indexed for HKUDS code intelligence.
            // Values: pending | indexing | indexed | failed
            $table->string('indexing_status', 20)->default('pending')->after('last_ping_status');
            $table->timestamp('last_indexed_at')->nullable()->after('indexing_status');
            $table->char('indexed_commit_sha', 40)->nullable()->after('last_indexed_at');

            $table->index(['team_id', 'indexing_status']);
        });
    }

    public function down(): void
    {
        Schema::table('git_repositories', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'indexing_status']);
            $table->dropColumn(['indexing_status', 'last_indexed_at', 'indexed_commit_sha']);
        });
    }
};
