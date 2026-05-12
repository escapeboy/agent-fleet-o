<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->string('suggested_type', 20)->nullable()->after('reported_type');
            $table->float('suggested_type_confidence')->nullable()->after('suggested_type');
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->dropColumn(['suggested_type', 'suggested_type_confidence']);
        });
    }
};
