<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table) {
            $table->jsonb('demo_surface')->nullable()->after('execution_profile');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table) {
            $table->dropColumn('demo_surface');
        });
    }
};
