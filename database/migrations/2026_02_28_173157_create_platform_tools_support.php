<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 0. Drop the RLS policy before altering the column it depends on (PostgreSQL only)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS team_isolation ON tools');
        }

        // 1. Make tools.team_id nullable and add is_platform flag
        Schema::table('tools', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->boolean('is_platform')->default(false)->after('team_id');
            $table->uuid('team_id')->nullable()->change();
        });

        Schema::table('tools', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
            $table->dropUnique(['team_id', 'slug']);
        });

        // Partial unique indexes: team tools and platform tools have separate slug namespaces
        DB::statement('CREATE UNIQUE INDEX tools_team_slug_unique ON tools (team_id, slug) WHERE team_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX tools_platform_slug_unique ON tools (slug) WHERE team_id IS NULL');

        // Recreate RLS policy: allow team rows AND platform rows (team_id IS NULL) (PostgreSQL only)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE POLICY team_isolation ON tools FOR ALL USING (team_id = current_team_id() OR team_id IS NULL)');
        }

        // 2. Per-team activation table for platform tools
        Schema::create('team_tool_activations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tool_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active'); // active | disabled
            $table->text('credential_overrides')->default('{}'); // encrypted at app layer
            $table->jsonb('config_overrides')->default('{}');
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'tool_id']);
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_tool_activations');

        DB::statement('DROP INDEX IF EXISTS tools_team_slug_unique');
        DB::statement('DROP INDEX IF EXISTS tools_platform_slug_unique');

        // Restore original RLS policy (PostgreSQL only)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS team_isolation ON tools');
        }

        Schema::table('tools', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn('is_platform');
            $table->uuid('team_id')->nullable(false)->change();
        });

        Schema::table('tools', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->unique(['team_id', 'slug']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE POLICY team_isolation ON tools FOR ALL USING (team_id = current_team_id())');
        }
    }
};
