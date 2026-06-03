<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Charter-as-contract (Squad borrow): a structured boundary statement for an
 * agent — what it owns, what it refuses, and who/when to escalate to. Rendered
 * into the system prompt when agent.charter.enabled is on. Additive + nullable;
 * a null charter is byte-for-byte legacy behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->jsonb('charter')->nullable()->after('constraints');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('charter');
        });
    }
};
