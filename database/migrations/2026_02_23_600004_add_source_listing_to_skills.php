<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->foreignUuid('source_listing_id')
                ->nullable()
                ->after('team_id')
                ->constrained('marketplace_listings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Domain\Marketplace\Models\MarketplaceListing::class, 'source_listing_id');
            $table->dropColumn('source_listing_id');
        });
    }
};
