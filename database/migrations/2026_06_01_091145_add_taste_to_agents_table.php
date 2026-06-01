<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Aesthetic / judgment persona axis: how the agent chooses among
            // acceptable options, tone, and what "good" looks like. Borrowed from
            // mercury-agent's taste.md. Distinct from backstory (history/context)
            // and heartbeat_definition (proactive scheduling).
            $table->text('taste')->nullable()->after('backstory');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('taste');
        });
    }
};
