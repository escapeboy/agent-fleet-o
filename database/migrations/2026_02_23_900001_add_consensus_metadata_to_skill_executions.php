<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_executions', function (Blueprint $table) {
            $table->decimal('confidence_score', 4, 3)->nullable()->after('output');
            $table->string('consensus_level')->nullable()->after('confidence_score'); // strong|moderate|weak
            $table->jsonb('peer_reviews')->nullable()->after('consensus_level');
        });
    }

    public function down(): void
    {
        Schema::table('skill_executions', function (Blueprint $table) {
            $table->dropColumn(['confidence_score', 'consensus_level', 'peer_reviews']);
        });
    }
};
