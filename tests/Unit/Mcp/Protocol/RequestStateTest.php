<?php

namespace Tests\Unit\Mcp\Protocol;

use App\Mcp\Protocol\RequestState;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * The SEP-2322 requestState envelope. Everything here is a security property:
 * the client is an untrusted intermediary and the spec makes validation a MUST.
 */
class RequestStateTest extends TestCase
{
    private function issue(array $overrides = []): RequestState
    {
        return RequestState::issue(
            approvalRequestId: $overrides['approval'] ?? 'approval-1',
            teamId: $overrides['team'] ?? 'team-1',
            // array_key_exists, not ??, so an explicit null user survives.
            userId: array_key_exists('user', $overrides) ? $overrides['user'] : 'user-1',
            tool: $overrides['tool'] ?? 'git_pr_merge',
            arguments: $overrides['args'] ?? ['pr_number' => 7, 'repository_id' => 'repo-1'],
        );
    }

    public function test_round_trips(): void
    {
        $decoded = RequestState::decode($this->issue()->encode());

        $this->assertNotNull($decoded);
        $this->assertSame('approval-1', $decoded->approvalRequestId);
        $this->assertSame('team-1', $decoded->teamId);
        $this->assertSame('user-1', $decoded->userId);
        $this->assertSame('git_pr_merge', $decoded->tool);
    }

    public function test_the_blob_does_not_leak_its_contents(): void
    {
        // Opaque to the client per the spec, and the ids inside are internal.
        $blob = $this->issue()->encode();

        $this->assertStringNotContainsString('approval-1', $blob);
        $this->assertStringNotContainsString('git_pr_merge', $blob);
    }

    public function test_garbage_decodes_to_null(): void
    {
        $this->assertNull(RequestState::decode('nonsense'));
        $this->assertNull(RequestState::decode(''));
        $this->assertNull(RequestState::decode(null));
    }

    public function test_tampered_blob_decodes_to_null(): void
    {
        $blob = $this->issue()->encode();
        $tampered = substr($blob, 0, -6).'AAAAAA';

        $this->assertNull(RequestState::decode($tampered));
    }

    public function test_a_blob_encrypted_with_a_foreign_payload_shape_decodes_to_null(): void
    {
        // Valid ciphertext from this app key, but not an envelope we minted.
        $this->assertNull(RequestState::decode(Crypt::encryptString(json_encode(['v' => 99]))));
        $this->assertNull(RequestState::decode(Crypt::encryptString('not json at all')));
    }

    public function test_expired_envelope_decodes_to_null(): void
    {
        $expired = new RequestState(
            approvalRequestId: 'a', teamId: 't', userId: 'u',
            tool: 'git_pr_merge', argsHash: str_repeat('0', 64),
            expiresAt: now()->subSecond()->getTimestamp(),
        );

        $this->assertNull(RequestState::decode($expired->encode()));
    }

    public function test_matches_the_call_it_was_minted_for(): void
    {
        $state = RequestState::decode($this->issue()->encode());

        $this->assertTrue($state->matches(
            'git_pr_merge',
            ['pr_number' => 7, 'repository_id' => 'repo-1'],
            'team-1',
            'user-1',
        ));
    }

    public function test_argument_order_does_not_matter_but_values_do(): void
    {
        $state = RequestState::decode($this->issue()->encode());

        // Re-serialised in a different key order — same call.
        $this->assertTrue($state->matches(
            'git_pr_merge',
            ['repository_id' => 'repo-1', 'pr_number' => 7],
            'team-1',
            'user-1',
        ));

        // Different PR — a state for #7 must not resume #9.
        $this->assertFalse($state->matches(
            'git_pr_merge',
            ['pr_number' => 9, 'repository_id' => 'repo-1'],
            'team-1',
            'user-1',
        ));
    }

    public function test_does_not_match_another_tool(): void
    {
        $state = RequestState::decode($this->issue()->encode());

        $this->assertFalse($state->matches(
            'git_pr_close',
            ['pr_number' => 7, 'repository_id' => 'repo-1'],
            'team-1',
            'user-1',
        ));
    }

    public function test_does_not_match_another_tenant(): void
    {
        $state = RequestState::decode($this->issue()->encode());

        $this->assertFalse($state->matches(
            'git_pr_merge',
            ['pr_number' => 7, 'repository_id' => 'repo-1'],
            'team-2',
            'user-1',
        ));
    }

    public function test_does_not_match_another_user(): void
    {
        $state = RequestState::decode($this->issue()->encode());

        $this->assertFalse($state->matches(
            'git_pr_merge',
            ['pr_number' => 7, 'repository_id' => 'repo-1'],
            'team-1',
            'user-2',
        ));

        // ...nor an anonymous caller replaying a user-bound state.
        $this->assertFalse($state->matches(
            'git_pr_merge',
            ['pr_number' => 7, 'repository_id' => 'repo-1'],
            'team-1',
            null,
        ));
    }

    public function test_a_state_minted_without_a_user_stays_team_bound_only(): void
    {
        // stdio / machine tokens have no user identity to bind to, so the
        // envelope must not demand one back — but it is still tenant-scoped.
        $state = RequestState::decode($this->issue(['user' => null])->encode());

        $this->assertNull($state->userId);
        $this->assertTrue($state->matches('git_pr_merge', ['pr_number' => 7, 'repository_id' => 'repo-1'], 'team-1', null));
        $this->assertTrue($state->matches('git_pr_merge', ['pr_number' => 7, 'repository_id' => 'repo-1'], 'team-1', 'user-9'));
        $this->assertFalse($state->matches('git_pr_merge', ['pr_number' => 7, 'repository_id' => 'repo-1'], 'team-2', null));
    }

    public function test_nested_argument_maps_are_order_normalised(): void
    {
        $state = RequestState::decode(RequestState::issue(
            approvalRequestId: 'a', teamId: 't', userId: 'u', tool: 'x',
            arguments: ['outer' => ['b' => 1, 'a' => 2], 'list' => [1, 2, 3]],
        )->encode());

        $this->assertTrue($state->matches('x', ['list' => [1, 2, 3], 'outer' => ['a' => 2, 'b' => 1]], 't', 'u'));

        // List order is meaningful and must NOT be normalised away.
        $this->assertFalse($state->matches('x', ['outer' => ['a' => 2, 'b' => 1], 'list' => [3, 2, 1]], 't', 'u'));
    }
}
