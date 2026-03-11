<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->foreignUuid('workflow_id')->nullable()->constrained('workflows')->nullOnDelete()->after('agent_id');
            $table->index(['team_id', 'workflow_id']);
        });
    }

    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropForeign(['workflow_id']);
            $table->dropIndex(['team_id', 'workflow_id']);
            $table->dropColumn('workflow_id');
        });
    }
};
