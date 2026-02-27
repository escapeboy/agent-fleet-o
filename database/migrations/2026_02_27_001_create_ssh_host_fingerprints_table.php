<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_host_fingerprints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('fingerprint_sha256');
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->unique(['team_id', 'host', 'port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssh_host_fingerprints');
    }
};
