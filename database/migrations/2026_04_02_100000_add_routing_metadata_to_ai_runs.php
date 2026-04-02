<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->string('classified_complexity', 20)->nullable()->after('has_reasoning');
            $table->string('budget_pressure_level', 20)->nullable()->after('classified_complexity');
            $table->unsignedTinyInteger('escalation_attempts')->default(0)->after('budget_pressure_level');
            $table->boolean('verification_passed')->nullable()->after('escalation_attempts');

            $table->index('classified_complexity');
            $table->index('budget_pressure_level');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropIndex(['classified_complexity']);
            $table->dropIndex(['budget_pressure_level']);
            $table->dropColumn([
                'classified_complexity',
                'budget_pressure_level',
                'escalation_attempts',
                'verification_passed',
            ]);
        });
    }
};
