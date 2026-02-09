<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\Models\AuditEntry;

class AuditControllerTest extends ApiTestCase
{
    public function test_can_list_audit_entries(): void
    {
        $this->actingAsApiUser();

        AuditEntry::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'event' => 'experiment.created',
            'properties' => ['name' => 'Test'],
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/audit');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'experiment.created');
    }

    public function test_can_filter_audit_by_event(): void
    {
        $this->actingAsApiUser();

        AuditEntry::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'event' => 'experiment.created',
            'properties' => [],
            'created_at' => now(),
        ]);

        AuditEntry::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'event' => 'approval.approved',
            'properties' => [],
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/audit?filter[event]=experiment.created');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_unauthenticated_cannot_list_audit(): void
    {
        $response = $this->getJson('/api/v1/audit');

        $response->assertStatus(401);
    }
}
