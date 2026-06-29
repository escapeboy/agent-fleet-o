<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `reason` was varchar(255) but stores free-text transition reasons —
        // including the evaluation agent's `reasoning` output, which overflows
        // 255 chars and threw SQLSTATE[22001], rolling back the whole
        // transition (Sentry #943).
        Schema::table('experiment_state_transitions', function (Blueprint $table): void {
            $table->text('reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('experiment_state_transitions', function (Blueprint $table): void {
            $table->string('reason')->nullable()->change();
        });
    }
};
