<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_dependencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUuid('depends_on_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();

            $table->string('reference_type', 30)->default('latest_run'); // latest_run | specific_run
            $table->foreignUuid('specific_run_id')->nullable()->constrained('project_runs')->nullOnDelete();

            $table->string('alias', 100);
            $table->jsonb('extract_config')->default('{}');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_required')->default(true);

            $table->timestamps();

            $table->unique(['project_id', 'depends_on_id']);
            $table->index(['project_id', 'sort_order']);
            $table->index('depends_on_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX idx_project_deps_extract ON project_dependencies USING gin (extract_config)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_dependencies');
    }
};
