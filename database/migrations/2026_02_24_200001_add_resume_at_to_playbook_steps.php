<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playbook_steps', function (Blueprint $table) {
            $table->timestampTz('resume_at')->nullable()->after('completed_at');
        });

        // Partial index for fast polling of expired time gates
        DB::statement("CREATE INDEX playbook_steps_waiting_time_gates_idx
            ON playbook_steps (resume_at)
            WHERE status = 'waiting_time' AND resume_at IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS playbook_steps_waiting_time_gates_idx');

        Schema::table('playbook_steps', function (Blueprint $table) {
            $table->dropColumn('resume_at');
        });
    }
};
