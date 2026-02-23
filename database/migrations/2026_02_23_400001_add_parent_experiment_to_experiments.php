<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiments', function (Blueprint $table) {
            $table->foreignUuid('parent_experiment_id')
                ->nullable()
                ->after('team_id')
                ->constrained('experiments')
                ->nullOnDelete();
            $table->unsignedSmallInteger('nesting_depth')
                ->default(0)
                ->after('parent_experiment_id');
        });
    }

    public function down(): void
    {
        Schema::table('experiments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_experiment_id');
            $table->dropColumn('nesting_depth');
        });
    }
};
