<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_installations', function (Blueprint $table) {
            $table->decimal('total_credits_spent', 14, 6)->default(0)->after('installed_workflow_id');
            $table->decimal('total_revenue_earned', 14, 6)->default(0)->after('total_credits_spent');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_installations', function (Blueprint $table) {
            $table->dropColumn(['total_credits_spent', 'total_revenue_earned']);
        });
    }
};
