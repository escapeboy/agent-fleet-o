<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that need team_id for multi-tenancy.
     * Excludes: global_settings (truly global), team_provider_credentials (already has team_id).
     */
    private array $tables = [
        'agents',
        'experiments',
        'experiment_stages',
        'experiment_state_transitions',
        'signals',
        'artifacts',
        'artifact_versions',
        'ai_runs',
        'outbound_proposals',
        'outbound_actions',
        'approval_requests',
        'metrics',
        'metric_aggregations',
        'credit_ledgers',
        'audit_entries',
        'connectors',
        'blacklists',
        'llm_request_logs',
        'circuit_breaker_states',
    ];

    public function up(): void
    {
        // Step 1: Add nullable team_id to all domain tables
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('team_id')->nullable()->after('id');
            });
        }

        // Step 2: Create default team and backfill
        $adminUser = DB::table('users')->first();

        if ($adminUser) {
            $teamId = (string) \Illuminate\Support\Str::uuid7();
            $now = now();

            DB::table('teams')->insert([
                'id' => $teamId,
                'name' => 'Default Team',
                'slug' => 'default',
                'owner_id' => $adminUser->id,
                'plan' => 'free',
                'settings' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('team_user')->insert([
                'team_id' => $teamId,
                'user_id' => $adminUser->id,
                'role' => 'owner',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('users')
                ->where('id', $adminUser->id)
                ->update(['current_team_id' => $teamId]);

            // Backfill all existing records
            foreach ($this->tables as $table) {
                DB::table($table)->whereNull('team_id')->update(['team_id' => $teamId]);
            }
        }

        // Step 3: Make NOT NULL + add FK + index
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('team_id')->nullable(false)->change();
                $t->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
                $t->index('team_id');
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign([$table . '_team_id_foreign'] ?? ["{$table}_team_id_foreign"]);
                $t->dropIndex([$table . '_team_id_index'] ?? ["{$table}_team_id_index"]);
                $t->dropColumn('team_id');
            });
        }

        // Remove default team (seeded data)
        DB::table('team_user')->where(
            'team_id',
            DB::table('teams')->where('slug', 'default')->value('id')
        )->delete();
        DB::table('teams')->where('slug', 'default')->delete();
    }
};
