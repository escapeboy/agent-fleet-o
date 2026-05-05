<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->foreignUuid('assigned_user_id')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_user_id');

            $table->index(['team_id', 'assigned_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'assigned_user_id']);
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn('assigned_at');
        });
    }
};
