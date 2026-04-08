<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_pages', function (Blueprint $table): void {
            // Stores the UUID injected by EnhanceWebsiteNavigationAction so that
            // PublicSiteController can validate form submissions against a known ID.
            $table->uuid('form_id')->nullable()->after('exported_css');
        });
    }

    public function down(): void
    {
        Schema::table('website_pages', function (Blueprint $table): void {
            $table->dropColumn('form_id');
        });
    }
};
