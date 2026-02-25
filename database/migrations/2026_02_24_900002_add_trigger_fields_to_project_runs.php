<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_runs', function (Blueprint $table) {
            $table->foreignUuid('trigger_rule_id')->nullable()->constrained('trigger_rules')->nullOnDelete();
            $table->foreignUuid('signal_id')->nullable()->constrained('signals')->nullOnDelete();
            $table->foreignUuid('triggered_by_conversation_id')
                ->nullable()
                ->constrained('assistant_conversations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignUuid('trigger_rule_id');
            $table->dropConstrainedForeignUuid('signal_id');
            $table->dropConstrainedForeignUuid('triggered_by_conversation_id');
        });
    }
};
