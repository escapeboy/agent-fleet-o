<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_entries', function (Blueprint $table) {
            $table->string('entry_hash', 64)->nullable();
            $table->string('prev_hash', 64)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            // The chaining scan only ever looks at unchained rows.
            DB::statement('CREATE INDEX audit_entries_unchained_idx ON audit_entries (team_id, id) WHERE entry_hash IS NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS audit_entries_unchained_idx');
        }

        Schema::table('audit_entries', function (Blueprint $table) {
            $table->dropColumn(['entry_hash', 'prev_hash']);
        });
    }
};
