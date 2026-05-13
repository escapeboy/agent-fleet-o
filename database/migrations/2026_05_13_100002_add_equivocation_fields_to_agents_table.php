<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->unsignedInteger('equivocation_count')->default(0)->after('last_health_check');
            $table->timestamp('last_equivocated_at')->nullable()->after('equivocation_count');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['equivocation_count', 'last_equivocated_at']);
        });
    }
};
