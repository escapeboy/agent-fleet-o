<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('version', 64);
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('share_token', 64)->nullable()->unique();
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['team_id', 'slug', 'version']);
            $table->index(['team_id', 'status']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX releases_published_idx ON releases (team_id, published_at DESC) WHERE published_at IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS releases_published_idx');
        }

        Schema::dropIfExists('releases');
    }
};
