<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_task_executions', function (Blueprint $table) {
            $table->unsignedSmallInteger('recovery_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('crew_task_executions', function (Blueprint $table) {
            $table->dropColumn('recovery_attempts');
        });
    }
};
