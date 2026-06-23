<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `avatar` was varchar(255) but OAuth providers (notably Google) return signed
 * avatar URLs well over 255 chars → SQLSTATE[22001] on the social-login insert
 * (Sentry #868/#869). Widen to text; URLs are unbounded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_social_accounts', function (Blueprint $table) {
            $table->text('avatar')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_social_accounts', function (Blueprint $table) {
            $table->string('avatar')->nullable()->change();
        });
    }
};
