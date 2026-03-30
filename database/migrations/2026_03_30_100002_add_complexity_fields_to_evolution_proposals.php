<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evolution_proposals', function (Blueprint $table) {
            $table->integer('complexity_delta')->nullable()->after('confidence_score');
            $table->float('complexity_penalty_applied')->nullable()->after('complexity_delta');
        });
    }

    public function down(): void
    {
        Schema::table('evolution_proposals', function (Blueprint $table) {
            $table->dropColumn(['complexity_delta', 'complexity_penalty_applied']);
        });
    }
};
