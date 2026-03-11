<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->uuid('chatbot_message_id')->nullable()->after('workflow_node_id');
            $table->string('edited_content')->nullable()->after('chatbot_message_id');

            $table->foreign('chatbot_message_id')
                ->references('id')
                ->on('chatbot_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropForeign(['chatbot_message_id']);
            $table->dropColumn(['chatbot_message_id', 'edited_content']);
        });
    }
};
