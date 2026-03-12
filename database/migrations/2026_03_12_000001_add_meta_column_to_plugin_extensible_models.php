<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a plugin-namespaced `meta` JSONB column to core models.
 *
 * This column allows plugins to store arbitrary data on existing records
 * without modifying the core schema. Each plugin's data is isolated under
 * its own key (plugin ID) within the JSONB object.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = ['agents', 'experiments', 'crews', 'skills', 'workflows', 'projects'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->jsonb('meta')->nullable()->after('id');
            });
        }
    }

    public function down(): void
    {
        $tables = ['agents', 'experiments', 'crews', 'skills', 'workflows', 'projects'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('meta');
            });
        }
    }
};
