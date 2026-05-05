<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_executions', function (Blueprint $table) {
            // Stores AGENTS.md / feature-list.json / progress.md / init.sh as a snapshot
            // so we can rehydrate the workspace on the next sandbox boot.
            $table->jsonb('workspace_contract')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_executions', function (Blueprint $table) {
            $table->dropColumn('workspace_contract');
        });
    }
};
