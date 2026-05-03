<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('git_repositories', function (Blueprint $table) {
            $table->string('commit_discipline', 16)->default('off')
                ->comment('off | atomic — when atomic, commit messages are rewritten via weak LLM into Conventional Commits.');
        });
    }

    public function down(): void
    {
        Schema::table('git_repositories', function (Blueprint $table) {
            $table->dropColumn('commit_discipline');
        });
    }
};
