<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_entries', function (Blueprint $table) {
            $table->text('decision_context')->nullable()->after('properties');
            $table->string('triggered_by')->nullable()->after('decision_context');
        });
    }

    public function down(): void
    {
        Schema::table('audit_entries', function (Blueprint $table) {
            $table->dropColumn(['decision_context', 'triggered_by']);
        });
    }
};
