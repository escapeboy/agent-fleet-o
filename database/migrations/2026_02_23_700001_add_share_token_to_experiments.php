<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiments', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique()->after('orchestration_config');
            $table->boolean('share_enabled')->default(false)->after('share_token');
            $table->jsonb('share_config')->nullable()->after('share_enabled');
            // share_config: {show_costs: bool, show_stages: bool, show_outputs: bool, expires_at: null|string}
        });
    }

    public function down(): void
    {
        Schema::table('experiments', function (Blueprint $table) {
            $table->dropColumn(['share_token', 'share_enabled', 'share_config']);
        });
    }
};
