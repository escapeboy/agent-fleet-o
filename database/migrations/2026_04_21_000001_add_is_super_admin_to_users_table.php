<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `is_super_admin` boolean to `users`.
 *
 * The cloud edition has had this column since 2026_02_07 — moved into base
 * so the column is available for tests and code paths that reference it
 * unconditionally (ApiTokenManageTool, TeamClaudeCodeVpsAccessTool, and the
 * ClaudeCodeVpsGate + LocalAgentGatewayVps test suites).
 *
 * Idempotent guard: skip the ADD COLUMN when the cloud migration already ran
 * on the same database (the cloud parent mounts base/migrations underneath
 * its own set, so both migrations can race on shared volumes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'is_super_admin')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'is_super_admin')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });
    }
};
