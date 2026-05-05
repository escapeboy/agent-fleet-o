<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_executions', function (Blueprint $table) {
            $table->foreignUuid('extracted_skill_id')->nullable()->constrained('skills')->nullOnDelete()->after('quality_details');
        });
    }

    public function down(): void
    {
        Schema::table('agent_executions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('extracted_skill_id');
        });
    }
};
