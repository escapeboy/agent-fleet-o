<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->float('confidence')->default(1.0)->after('source_id');
            $table->jsonb('tags')->default('[]')->after('confidence');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX memories_agent_confidence_idx ON memories (agent_id, confidence)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS memories_agent_confidence_idx');
        }

        Schema::table('memories', function (Blueprint $table) {
            $table->dropColumn(['confidence', 'tags']);
        });
    }
};
