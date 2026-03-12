<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_executions', function (Blueprint $table) {
            $table->unsignedSmallInteger('delegation_depth')->default(0)->after('error_message');
        });

        Schema::table('project_runs', function (Blueprint $table) {
            $table->unsignedSmallInteger('delegation_depth')->default(0)->after('error_message');
        });

        Schema::table('experiments', function (Blueprint $table) {
            $table->unsignedSmallInteger('delegation_depth')->default(0)->after('nesting_depth');
        });
    }

    public function down(): void
    {
        Schema::table('crew_executions', function (Blueprint $table) {
            $table->dropColumn('delegation_depth');
        });

        Schema::table('project_runs', function (Blueprint $table) {
            $table->dropColumn('delegation_depth');
        });

        Schema::table('experiments', function (Blueprint $table) {
            $table->dropColumn('delegation_depth');
        });
    }
};
