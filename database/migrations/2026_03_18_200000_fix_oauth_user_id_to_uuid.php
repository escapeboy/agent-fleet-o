<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // oauth_access_tokens.user_id was created as bigint but users have UUID primary keys.
        // ALTER COLUMN handles the cast; there are no existing rows so no data migration needed.
        DB::statement('ALTER TABLE oauth_access_tokens ALTER COLUMN user_id TYPE uuid USING user_id::text::uuid');
        DB::statement('ALTER TABLE oauth_auth_codes ALTER COLUMN user_id TYPE uuid USING user_id::text::uuid');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE oauth_access_tokens ALTER COLUMN user_id TYPE bigint USING user_id::text::bigint');
        DB::statement('ALTER TABLE oauth_auth_codes ALTER COLUMN user_id TYPE bigint USING user_id::text::bigint');
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
