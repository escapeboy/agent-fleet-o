<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('toolsets', function (Blueprint $table) {
            // Browser Harness (build #4): per-toolset persisted CDP helpers.
            // Shape: ['helpers' => [{name: string, code: string, approved: bool, added_by: ?uuid, added_at: timestamp}, ...]]
            if (DB::getDriverName() === 'pgsql') {
                $table->jsonb('browser_helpers')->nullable();
            } else {
                $table->json('browser_helpers')->nullable();
            }
            $table->boolean('browser_helpers_pending_review')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('toolsets', function (Blueprint $table) {
            $table->dropColumn(['browser_helpers', 'browser_helpers_pending_review']);
        });
    }
};
