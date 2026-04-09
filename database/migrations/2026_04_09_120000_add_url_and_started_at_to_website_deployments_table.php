<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_deployments', function (Blueprint $table) {
            $table->string('url', 2048)->nullable()->after('status');
            $table->timestamp('started_at')->nullable()->after('deployed_at');
        });
    }

    public function down(): void
    {
        Schema::table('website_deployments', function (Blueprint $table) {
            $table->dropColumn(['url', 'started_at']);
        });
    }
};
