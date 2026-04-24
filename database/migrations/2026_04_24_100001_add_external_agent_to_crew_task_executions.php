<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_task_executions', function (Blueprint $table): void {
            $table->foreignUuid('external_agent_id')->nullable()->after('agent_id')
                ->constrained('external_agents')->nullOnDelete();
            $table->index(['external_agent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('crew_task_executions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('external_agent_id');
        });
    }
};
