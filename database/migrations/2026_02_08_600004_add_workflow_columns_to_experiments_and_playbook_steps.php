<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiments', function (Blueprint $table) {
            $table->foreignUuid('workflow_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->integer('workflow_version')->nullable()->after('workflow_id');
        });

        Schema::table('playbook_steps', function (Blueprint $table) {
            $table->foreignUuid('workflow_node_id')->nullable()->after('skill_id')->constrained('workflow_nodes')->nullOnDelete();
            $table->integer('loop_count')->default(0)->after('cost_credits');
        });
    }

    public function down(): void
    {
        Schema::table('playbook_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workflow_node_id');
            $table->dropColumn('loop_count');
        });

        Schema::table('experiments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workflow_id');
            $table->dropColumn('workflow_version');
        });
    }
};
