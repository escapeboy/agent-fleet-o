<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->unsignedInteger('duplicate_count')->default(0)->after('tags');
            $table->timestamp('last_received_at')->nullable()->after('duplicate_count');
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropColumn(['duplicate_count', 'last_received_at']);
        });
    }
};
