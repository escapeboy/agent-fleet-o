<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_chunks', function (Blueprint $table) {
            // {"allowed_group_slugs": ["team:UUID:role:admin", ...]}; null = unrestricted.
            $table->jsonb('source_acl')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_chunks', function (Blueprint $table) {
            $table->dropColumn('source_acl');
        });
    }
};
