<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->decimal('risk_score', 5, 2)->nullable()->after('last_health_check');
            $table->jsonb('risk_profile')->nullable()->after('risk_score');
            $table->timestamp('risk_profile_updated_at')->nullable()->after('risk_profile');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['risk_score', 'risk_profile', 'risk_profile_updated_at']);
        });
    }
};
