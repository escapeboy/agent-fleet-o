<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->jsonb('server_capabilities')->nullable()->after('tool_definitions');
        });
    }

    public function down(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->dropColumn('server_capabilities');
        });
    }
};
