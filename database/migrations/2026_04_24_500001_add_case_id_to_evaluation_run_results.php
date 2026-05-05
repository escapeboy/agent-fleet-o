<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_run_results', function (Blueprint $table) {
            $table->uuid('case_id')->nullable()->after('row_id');
            $table->index('case_id', 'eval_run_results_case_idx');
            $table->foreign('case_id')->references('id')->on('evaluation_cases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_run_results', function (Blueprint $table) {
            $table->dropForeign(['case_id']);
            $table->dropIndex('eval_run_results_case_idx');
            $table->dropColumn('case_id');
        });
    }
};
