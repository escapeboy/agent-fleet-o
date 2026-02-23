<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiment_stages', function (Blueprint $table) {
            $table->unsignedSmallInteger('recovery_attempts')->default(0)->after('retry_count');
            $table->timestamp('last_recovery_at')->nullable()->after('recovery_attempts');
            $table->string('recovery_reason', 50)->nullable()->after('last_recovery_at');
        });
    }

    public function down(): void
    {
        Schema::table('experiment_stages', function (Blueprint $table) {
            $table->dropColumn(['recovery_attempts', 'last_recovery_at', 'recovery_reason']);
        });
    }
};
