<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->text('signature')->nullable();
            $table->uuid('signing_key_id')->nullable();
            $table->timestamp('signed_at')->nullable();

            $table->foreign('signing_key_id')->references('id')->on('release_signing_keys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropForeign(['signing_key_id']);
            $table->dropColumn(['signature', 'signing_key_id', 'signed_at']);
        });
    }
};
