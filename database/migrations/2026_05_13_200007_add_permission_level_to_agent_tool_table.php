<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_tool', function (Blueprint $table) {
            $table->string('permission_level')->default('write')->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('agent_tool', function (Blueprint $table) {
            $table->dropColumn('permission_level');
        });
    }
};
