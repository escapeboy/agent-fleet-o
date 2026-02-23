<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->boolean('evaluation_enabled')->default(false)->after('budget_spent_credits');
            $table->float('evaluation_sample_rate')->default(0.2)->after('evaluation_enabled');
            $table->string('evaluation_model', 100)->nullable()->after('evaluation_sample_rate');
            $table->jsonb('evaluation_criteria')->nullable()->after('evaluation_model');
        });

        Schema::table('skills', function (Blueprint $table) {
            $table->boolean('evaluation_enabled')->default(false)->after('status');
            $table->float('evaluation_sample_rate')->default(0.2)->after('evaluation_enabled');
            $table->string('evaluation_model', 100)->nullable()->after('evaluation_sample_rate');
            $table->jsonb('evaluation_criteria')->nullable()->after('evaluation_model');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['evaluation_enabled', 'evaluation_sample_rate', 'evaluation_model', 'evaluation_criteria']);
        });

        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn(['evaluation_enabled', 'evaluation_sample_rate', 'evaluation_model', 'evaluation_criteria']);
        });
    }
};
