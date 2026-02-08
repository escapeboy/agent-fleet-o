<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
            $table->string('role')->nullable()->after('slug');
            $table->text('goal')->nullable()->after('role');
            $table->text('backstory')->nullable()->after('goal');
            $table->jsonb('constraints')->default('{}')->after('capabilities');
            $table->integer('budget_cap_credits')->nullable()->after('constraints');
            $table->integer('budget_spent_credits')->default(0)->after('budget_cap_credits');
            $table->softDeletes();

            $table->unique(['team_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'slug']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'slug', 'role', 'goal', 'backstory',
                'constraints', 'budget_cap_credits', 'budget_spent_credits',
            ]);
        });
    }
};
