<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_nodes', function (Blueprint $table) {
            $table->foreignUuid('guardrail_skill_id')->nullable()
                ->constrained('skills')->nullOnDelete()
                ->after('crew_id');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_nodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guardrail_skill_id');
        });
    }
};
