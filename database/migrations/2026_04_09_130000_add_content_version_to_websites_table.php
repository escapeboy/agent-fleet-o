<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            // Monotonically-increasing version bumped whenever any page in
            // the website is created, updated, or deleted. Used as part of
            // the widget cache key so cache invalidation is O(1) — we just
            // bump the version and old keys become unreachable.
            $table->unsignedBigInteger('content_version')->default(1)->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('content_version');
        });
    }
};
