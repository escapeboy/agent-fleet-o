<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->jsonb('tags')->default('[]')->after('risk_level');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX tools_tags_gin ON tools USING gin(tags)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS tools_tags_gin');
        }

        Schema::table('tools', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
