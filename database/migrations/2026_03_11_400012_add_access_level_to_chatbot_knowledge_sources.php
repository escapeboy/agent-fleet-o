<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_knowledge_sources', function (Blueprint $table) {
            $table->string('access_level', 20)->default('public')->after('name');
            $table->index(['chatbot_id', 'access_level']);
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_knowledge_sources', function (Blueprint $table) {
            $table->dropIndex(['chatbot_id', 'access_level']);
            $table->dropColumn('access_level');
        });
    }
};
