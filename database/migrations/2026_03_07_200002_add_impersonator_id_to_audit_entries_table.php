<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_entries', function (Blueprint $table) {
            $table->uuid('impersonator_id')->nullable()->after('user_id');
            $table->foreign('impersonator_id')->references('id')->on('users')->nullOnDelete();
            $table->index('impersonator_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_entries', function (Blueprint $table) {
            $table->dropForeign(['impersonator_id']);
            $table->dropIndex(['impersonator_id']);
            $table->dropColumn('impersonator_id');
        });
    }
};
