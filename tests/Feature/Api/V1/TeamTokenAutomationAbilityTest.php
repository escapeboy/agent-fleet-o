<?php

namespace Tests\Feature\Api\V1;

use App\Infrastructure\Auth\SanctumTokenIssuer;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class TeamTokenAutomationAbilityTest extends ApiTestCase
{
    /** Real personal access token (Sanctum::actingAs mocks one without abilities). */
    private function actingWithToken(User $user): static
    {
        $this->withToken(SanctumTokenIssuer::create($user, 't', ['team:'.$this->team->id])->plainTextToken);

        return $this;
    }

    public function test_owner_can_issue_automation_token(): void
    {
        $this->actingWithToken($this->user);

        $this->postJson('/api/v1/team/tokens', [
            'name' => 'automation worker',
            'abilities' => ['team:'.$this->team->id, 'automation'],
        ])->assertCreated();

        $token = PersonalAccessToken::where('name', 'automation worker')->sole();
        $this->assertContains('automation', $token->abilities);
        $this->assertNull($token->expires_at);
    }

    public function test_member_cannot_issue_automation_token(): void
    {
        $member = $this->createTeamMember('member');
        $this->actingWithToken($member);

        $this->postJson('/api/v1/team/tokens', [
            'name' => 'automation worker',
            'abilities' => ['team:'.$this->team->id, 'automation'],
        ])->assertForbidden();

        $this->assertFalse(PersonalAccessToken::where('name', 'automation worker')->exists());
    }

    public function test_unknown_ability_still_rejected(): void
    {
        $this->actingWithToken($this->user);

        $this->postJson('/api/v1/team/tokens', [
            'name' => 'bad',
            'abilities' => ['something-else'],
        ])->assertStatus(422);
    }
}
