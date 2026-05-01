<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiment_stages', function (Blueprint $table) {
            $table->text('annotation')->nullable()->after('searchable_text');
        });
    }

    public function down(): void
    {
        Schema::table('experiment_stages', function (Blueprint $table) {
            $table->dropColumn('annotation');
        });
    }
};
