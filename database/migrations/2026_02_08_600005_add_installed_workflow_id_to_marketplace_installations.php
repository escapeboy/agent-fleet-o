<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_installations', function (Blueprint $table) {
            $table->foreignUuid('installed_workflow_id')
                ->nullable()
                ->after('installed_agent_id')
                ->constrained('workflows')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_installations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('installed_workflow_id');
        });
    }
};
