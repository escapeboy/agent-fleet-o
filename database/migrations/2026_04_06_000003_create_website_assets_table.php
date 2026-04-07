<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('filename', 255);
            $table->string('disk', 50)->default('local');
            $table->string('path', 1000);
            $table->string('url', 1000)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->bigInteger('size_bytes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_assets');
    }
};
