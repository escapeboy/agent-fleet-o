<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->foreignUuid('contact_identity_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete()
                ->after('experiment_id');

            $table->index(['team_id', 'contact_identity_id']);
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropForeign(['contact_identity_id']);
            $table->dropIndex(['team_id', 'contact_identity_id']);
            $table->dropColumn('contact_identity_id');
        });
    }
};
