<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_schedules', function (Blueprint $table) {
            $table->timestamp('queued_run_at')->nullable()->after('next_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_schedules', function (Blueprint $table) {
            $table->dropColumn('queued_run_at');
        });
    }
};
