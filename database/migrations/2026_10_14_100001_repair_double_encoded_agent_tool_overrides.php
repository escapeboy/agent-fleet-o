<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The API agent update and the workspace import stored agent_tool.overrides as a
 * JSON string ("{}") instead of an object. Each later update wrapped it once more,
 * so unwrap until no string values remain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        for ($pass = 0; $pass < 10; $pass++) {
            $updated = DB::update(
                "UPDATE agent_tool SET overrides = (overrides #>> '{}')::jsonb WHERE jsonb_typeof(overrides) = 'string'",
            );

            if ($updated === 0) {
                break;
            }
        }
    }

    public function down(): void
    {
        // Data repair only; the broken encoding is not restored.
    }
};
