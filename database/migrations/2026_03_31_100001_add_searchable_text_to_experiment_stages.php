<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiment_stages', function (Blueprint $table) {
            $table->text('searchable_text')->nullable()->after('output_snapshot');
        });

        // PostgreSQL-specific: generated tsvector column + GIN index for FTS
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE experiment_stages ADD COLUMN searchable_tsv tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(searchable_text, ''))) STORED");
            DB::statement('CREATE INDEX idx_experiment_stages_searchable_tsv ON experiment_stages USING gin(searchable_tsv)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_experiment_stages_searchable_tsv');
            DB::statement('ALTER TABLE experiment_stages DROP COLUMN IF EXISTS searchable_tsv');
        }

        Schema::table('experiment_stages', function (Blueprint $table) {
            $table->dropColumn('searchable_text');
        });
    }
};
