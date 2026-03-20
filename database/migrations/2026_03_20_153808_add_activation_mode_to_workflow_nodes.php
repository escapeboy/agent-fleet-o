<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_nodes', function (Blueprint $table) {
            $table->string('activation_mode', 10)->default('all')->after('config');
            $table->unsignedSmallInteger('activation_threshold')->nullable()->after('activation_mode');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_nodes', function (Blueprint $table) {
            $table->dropColumn(['activation_mode', 'activation_threshold']);
        });
    }
};
