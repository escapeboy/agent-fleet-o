<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_executions', function (Blueprint $table) {
            $table->float('quality_score')->nullable()->after('cost_credits');
            $table->jsonb('quality_details')->nullable()->after('quality_score');
            $table->string('evaluation_method', 30)->nullable()->after('quality_details');
            $table->string('judge_model', 100)->nullable()->after('evaluation_method');
        });

        Schema::table('skill_executions', function (Blueprint $table) {
            $table->float('quality_score')->nullable()->after('cost_credits');
            $table->jsonb('quality_details')->nullable()->after('quality_score');
            $table->string('evaluation_method', 30)->nullable()->after('quality_details');
            $table->string('judge_model', 100)->nullable()->after('evaluation_method');
        });
    }

    public function down(): void
    {
        Schema::table('agent_executions', function (Blueprint $table) {
            $table->dropColumn(['quality_score', 'quality_details', 'evaluation_method', 'judge_model']);
        });

        Schema::table('skill_executions', function (Blueprint $table) {
            $table->dropColumn(['quality_score', 'quality_details', 'evaluation_method', 'judge_model']);
        });
    }
};
