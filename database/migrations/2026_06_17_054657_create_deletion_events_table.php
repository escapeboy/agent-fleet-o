<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('deletion_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->nullable()->index();
            $table->uuid('agent_id')->nullable();
            $table->string('scope'); // team|agent|project
            $table->string('reason'); // gdpr_erasure|manual
            $table->jsonb('purged_counts')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // kg_communities.team_id exists (NOT NULL) but is index-only — add the cascade FK.
        if (Schema::hasColumn('kg_communities', 'team_id') && ! $this->hasForeignKey('kg_communities', 'kg_communities_team_id_foreign')) {
            // Adding a cascade FK fails if existing rows reference a non-existent
            // team. team_id is NOT NULL here, so orphaned communities (team since
            // deleted) are junk — purge them before constraining.
            $this->purgeOrphans('kg_communities', nullable: false);
            Schema::table('kg_communities', function (Blueprint $table) {
                $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            });
        }

        // semantic_cache_entries.team_id is nullable with no FK. The cascade FK is valid:
        // null-team (cross-team / platform) rows are simply unaffected by a team delete.
        if (Schema::hasColumn('semantic_cache_entries', 'team_id') && ! $this->hasForeignKey('semantic_cache_entries', 'semantic_cache_entries_team_id_foreign')) {
            // Orphaned cache rows (team since deleted) would block the FK. They are
            // just cache — demote them to cross-team (NULL) rather than deleting.
            $this->purgeOrphans('semantic_cache_entries', nullable: true);
            Schema::table('semantic_cache_entries', function (Blueprint $table) {
                $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            });
        }
    }

    /**
     * Make a team_id column FK-safe: rows whose team_id has no matching team are
     * NULLed (nullable column) or deleted (NOT NULL column). Postgres-only; on
     * SQLite (tests) FKs aren't enforced so this is a no-op.
     */
    private function purgeOrphans(string $table, bool $nullable): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $orphans = DB::table($table)
            ->whereNotNull('team_id')
            ->whereNotIn('team_id', fn ($q) => $q->select('id')->from('teams'));

        if ($nullable) {
            (clone $orphans)->update(['team_id' => null]);
        } else {
            (clone $orphans)->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->hasForeignKey('semantic_cache_entries', 'semantic_cache_entries_team_id_foreign')) {
            Schema::table('semantic_cache_entries', function (Blueprint $table) {
                $table->dropForeign('semantic_cache_entries_team_id_foreign');
            });
        }

        if ($this->hasForeignKey('kg_communities', 'kg_communities_team_id_foreign')) {
            Schema::table('kg_communities', function (Blueprint $table) {
                $table->dropForeign('kg_communities_team_id_foreign');
            });
        }

        Schema::dropIfExists('deletion_events');
    }

    /**
     * SQLite (test DB) can't introspect FKs by name and doesn't enforce them the same way;
     * treat it as "no FK present" so the guarded add/drop is a safe no-op there.
     */
    private function hasForeignKey(string $table, string $constraint): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        return DB::table('information_schema.table_constraints')
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
