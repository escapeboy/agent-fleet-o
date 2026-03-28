<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contact_identities', function (Blueprint $table) {
            $table->integer('risk_score')->default(0)->after('metadata');
            $table->jsonb('risk_flags')->nullable()->after('risk_score');
            $table->timestamp('risk_evaluated_at')->nullable()->after('risk_flags');
            $table->index(['team_id', 'risk_score'], 'contact_identities_team_risk_score_index');
        });
    }

    public function down(): void
    {
        Schema::table('contact_identities', function (Blueprint $table) {
            $table->dropIndex('contact_identities_team_risk_score_index');
            $table->dropColumn(['risk_score', 'risk_flags', 'risk_evaluated_at']);
        });
    }
};
