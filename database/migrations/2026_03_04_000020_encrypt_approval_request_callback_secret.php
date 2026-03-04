<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen callback_secret from varchar(64) to text so it can hold
     * the TeamEncryptedString cipher-text (~224 chars for a 64-char secret).
     */
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->text('callback_secret')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->string('callback_secret', 64)->nullable()->change();
        });
    }
};
