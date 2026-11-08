<?php

namespace Tests\Feature\Api\V1;

use App\Infrastructure\Auth\SanctumTokenIssuer;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class TeamTokenAutomationAbilityTest extends ApiTestCase
{
    private function actingAsTokenUser(User $user): static
    {
        $this->withToken(SanctumTokenIssuer::create($user, 'issuer', ['team:'.$this->team->id])->plainTextToken);

        return $this;
    }

    public function test_owner_can_issue_automation_token(): void
    {
        $this->actingAsTokenUser($this->user);

        $this->postJson('/api/v1/team/tokens', [
            'name' => 'automation bot',
            'abilities' => ['team:'.$this->team->id, 'automation'],
        ])->assertCreated();

        $token = PersonalAccessToken::where('name', 'automation bot')->sole();
        $this->assertContains('automation', $token->abilities);
        $this->assertContains('team:'.$this->team->id, $token->abilities);
    }

    public function test_member_cannot_issue_automation_token(): void
    {
        $member = $this->createTeamMember('member');
        $this->actingAsTokenUser($member);

        $this->postJson('/api/v1/team/tokens', [
            'name' => 'automation bot',
            'abilities' => ['team:'.$this->team->id, 'automation'],
        ])->assertForbidden();

        $this->assertSame(0, PersonalAccessToken::where('name', 'automation bot')->count());
    }

    public function test_unknown_ability_still_rejected(): void
    {
        $this->actingAsTokenUser($this->user);

        $this->postJson('/api/v1/team/tokens', [
            'name' => 'odd',
            'abilities' => ['something-else'],
        ])->assertStatus(422);
    }
}
