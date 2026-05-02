<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evolution_proposals', function (Blueprint $table) {
            $table->string('evolution_type', 50)->default('agent_config')->after('trigger');
            $table->jsonb('mutation_variant')->nullable()->after('evolution_type');
        });
    }

    public function down(): void
    {
        Schema::table('evolution_proposals', function (Blueprint $table) {
            $table->dropColumn(['evolution_type', 'mutation_variant']);
        });
    }
};
