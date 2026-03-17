<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->uuid('knowledge_base_id')->nullable()->after('team_id');
            $table->foreign('knowledge_base_id')->references('id')->on('knowledge_bases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['knowledge_base_id']);
            $table->dropColumn('knowledge_base_id');
        });
    }
};
