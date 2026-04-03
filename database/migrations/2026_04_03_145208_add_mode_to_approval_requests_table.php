<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->string('mode')->default('in_loop')->after('status');
            $table->unsignedInteger('intervention_window_seconds')->nullable()->after('mode');
            $table->timestamp('auto_approved_at')->nullable()->after('intervention_window_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropColumn(['mode', 'intervention_window_seconds', 'auto_approved_at']);
        });
    }
};
