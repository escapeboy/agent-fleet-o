<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->string('callback_url')->nullable()->after('escalation_level');
            $table->string('callback_secret', 64)->nullable()->after('callback_url');
            $table->timestamp('callback_fired_at')->nullable()->after('callback_secret');
            $table->string('callback_status', 20)->nullable()->after('callback_fired_at');
            // callback_status: null | 'pending' | 'delivered' | 'failed'
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropColumn(['callback_url', 'callback_secret', 'callback_fired_at', 'callback_status']);
        });
    }
};
