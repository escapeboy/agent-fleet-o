<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `trigger` was varchar(20) but callers pass arbitrary labels via MCP/API
 * (e.g. "manual-openrouter-test", 22 chars) → SQLSTATE[22001] on insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_runs', function (Blueprint $table) {
            $table->string('trigger', 64)->default('schedule')->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_runs', function (Blueprint $table) {
            $table->string('trigger', 20)->default('schedule')->change();
        });
    }
};
