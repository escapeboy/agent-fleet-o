<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_run_results', function (Blueprint $table) {
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });

        // Scores are 0.0–1.0 range so decimal(4,2) is fine, but add updated_at for Eloquent compatibility
    }

    public function down(): void
    {
        Schema::table('evaluation_run_results', function (Blueprint $table) {
            $table->dropColumn('updated_at');
        });
    }
};
