<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_executions', function (Blueprint $table) {
            $table->string('trust_mode')->nullable()->after('quality_dimensions');
        });
    }

    public function down(): void
    {
        Schema::table('crew_executions', function (Blueprint $table) {
            $table->dropColumn('trust_mode');
        });
    }
};
