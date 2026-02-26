<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('display_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable()->index();   // E.164 normalized (+12125551234)
            $table->json('metadata')->nullable();           // notes, tags, custom fields
            $table->timestamps();

            $table->index(['team_id', 'email']);
            $table->index(['team_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_identities');
    }
};
