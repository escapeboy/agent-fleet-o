<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playbook_steps', function (Blueprint $table) {
            $table->jsonb('guardrail_result')->nullable()->after('checkpoint_version');
        });
    }

    public function down(): void
    {
        Schema::table('playbook_steps', function (Blueprint $table) {
            $table->dropColumn('guardrail_result');
        });
    }
};
