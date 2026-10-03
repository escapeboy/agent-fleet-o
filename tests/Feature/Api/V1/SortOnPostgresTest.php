<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\Models\AuditEntry;
use Illuminate\Support\Facades\DB;

/**
 * Postgres-only coverage for API list sorting (spatie/laravel-query-builder →
 * Query\Builder::orderBy). The 2026-06-10 incident (framework 13.15 +
 * query-builder 7.3.0) failed only on the Postgres grammar, which the SQLite
 * suite never exercises. Skipped on SQLite; run with DB_CONNECTION=pgsql.
 */
class SortOnPostgresTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Postgres-only: run with DB_CONNECTION=pgsql.');
        }
    }

    public function test_api_list_sorts_both_directions_on_postgres(): void
    {
        $this->actingAsApiUser();

        foreach (['first' => now()->subMinute(), 'second' => now()] as $event => $at) {
            AuditEntry::create(['team_id' => $this->team->id, 'event' => $event, 'properties' => [], 'created_at' => $at]);
        }

        $this->getJson('/api/v1/audit?sort=-created_at')->assertOk()->assertJsonPath('data.0.event', 'second');
        $this->getJson('/api/v1/audit?sort=created_at')->assertOk()->assertJsonPath('data.0.event', 'first');
        $this->getJson('/api/v1/audit')->assertOk()->assertJsonPath('data.0.event', 'second');
    }
}
