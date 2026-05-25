<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            // Number of times this signal recurred after being Resolved — a
            // chronic, non-durable fix. Drives fix-durability triage signals.
            $table->unsignedInteger('recurrence_count')->default(0);
            $table->index(['team_id', 'recurrence_count'], 'signals_team_recurrence_idx');
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropIndex('signals_team_recurrence_idx');
            $table->dropColumn('recurrence_count');
        });
    }
};
