<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_proposals', function (Blueprint $table) {
            $table->unsignedTinyInteger('rubric_score')->nullable()->after('risk_level');
            $table->jsonb('rubric_breakdown')->nullable()->after('rubric_score');
        });
    }

    public function down(): void
    {
        Schema::table('action_proposals', function (Blueprint $table) {
            $table->dropColumn(['rubric_score', 'rubric_breakdown']);
        });
    }
};
