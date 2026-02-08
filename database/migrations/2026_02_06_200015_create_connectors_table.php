<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connectors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type'); // input|output
            $table->string('driver'); // rss|webhook|scrape|email|smtp|telegram|slack
            $table->string('name');
            $table->jsonb('config'); // encrypted sensitive fields
            $table->string('status')->default('active'); // active|disabled|error
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_message')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connectors');
    }
};
