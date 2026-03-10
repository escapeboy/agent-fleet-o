<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webauthn_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');

            // WebAuthn credential fields
            $table->string('credential_id', 500)->unique();
            $table->string('type', 32)->default('public-key');
            $table->json('transports')->nullable();
            $table->string('attestation_type', 255)->nullable();
            $table->json('trust_path')->nullable();
            $table->uuid('aaguid')->nullable();
            $table->text('credential_public_key');
            $table->unsignedBigInteger('counter')->default(0);

            // User-facing label (e.g. "MacBook Touch ID", "iPhone Face ID")
            $table->string('name')->default('Security Key');

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webauthn_keys');
    }
};
