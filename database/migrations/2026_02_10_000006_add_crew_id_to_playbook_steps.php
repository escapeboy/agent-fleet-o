<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playbook_steps', function (Blueprint $table) {
            $table->foreignUuid('crew_id')->nullable()->after('workflow_node_id')->constrained('crews')->nullOnDelete();
        });

        // Make agent_id nullable — crew nodes don't have an agent
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE playbook_steps ALTER COLUMN agent_id DROP NOT NULL');
        } else {
            Schema::table('playbook_steps', function (Blueprint $table) {
                $table->foreignUuid('agent_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('playbook_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crew_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE playbook_steps ALTER COLUMN agent_id SET NOT NULL');
        } else {
            Schema::table('playbook_steps', function (Blueprint $table) {
                $table->foreignUuid('agent_id')->nullable(false)->change();
            });
        }
    }
};
