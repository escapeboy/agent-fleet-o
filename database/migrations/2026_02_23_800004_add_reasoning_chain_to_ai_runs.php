<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->jsonb('reasoning_chain')->nullable()->after('raw_output');
            $table->boolean('has_reasoning')->default(false)->after('reasoning_chain');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn(['reasoning_chain', 'has_reasoning']);
        });
    }
};
