<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_nodes', function (Blueprint $table) {
            $table->foreignUuid('crew_id')->nullable()->after('skill_id')->constrained('crews')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_nodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crew_id');
        });
    }
};
