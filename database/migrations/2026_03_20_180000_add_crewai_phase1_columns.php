<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Feature 5: Conditional Crew Tasks
        Schema::table('crew_task_executions', function (Blueprint $table) {
            $table->jsonb('skip_condition')->nullable()->after('depends_on');
        });

        // Feature 2: Composite Memory Scoring
        Schema::table('memories', function (Blueprint $table) {
            $table->float('importance')->default(0.5)->after('confidence');
            $table->timestamp('last_accessed_at')->nullable()->after('importance');
        });

        // Feature 3: result_as_answer flag on tools
        Schema::table('tools', function (Blueprint $table) {
            $table->boolean('result_as_answer')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('crew_task_executions', function (Blueprint $table) {
            $table->dropColumn('skip_condition');
        });

        Schema::table('memories', function (Blueprint $table) {
            $table->dropColumn(['importance', 'last_accessed_at']);
        });

        Schema::table('tools', function (Blueprint $table) {
            $table->dropColumn('result_as_answer');
        });
    }
};
