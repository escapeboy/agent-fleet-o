<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_signing_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();           // this IS the kid
            $table->uuid('team_id');
            $table->text('public_key');              // base64(32 bytes)
            $table->text('secret_data');             // envelope-encrypted
            $table->string('status', 16)->default('active');  // active|grace|revoked
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('grace_expires_at')->nullable();
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_signing_keys');
    }
};
