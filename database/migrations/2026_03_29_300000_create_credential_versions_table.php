<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('credential_id')->constrained('credentials')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams');
            $table->text('secret_data'); // TeamEncryptedArray cast — same encryption as credentials.secret_data
            $table->integer('version_number'); // monotonically increasing per credential
            $table->string('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['credential_id', 'version_number']);
            $table->index(['team_id', 'credential_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_versions');
    }
};
