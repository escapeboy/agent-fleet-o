<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->boolean('strict_mode')->default(false)->after('output_schema_max_retries');
            $table->string('tool_permission_default')->default('write')->after('strict_mode');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['strict_mode', 'tool_permission_default']);
        });
    }
};
