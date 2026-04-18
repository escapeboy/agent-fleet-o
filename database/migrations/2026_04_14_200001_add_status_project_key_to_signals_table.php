<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->string('status', 30)->default('received')->after('content_hash');
            $table->string('project_key', 100)->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropIndex(['project_key']);
            $table->dropColumn(['status', 'project_key']);
        });
    }
};
