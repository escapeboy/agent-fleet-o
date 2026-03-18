<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TeamResource;
use App\Infrastructure\Auth\SanctumTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * @tags Team
 */
class TeamController extends Controller
{
    public function show(Request $request): TeamResource
    {
        $team = Team::withCount('users')->findOrFail($request->user()->current_team_id);

        return new TeamResource($team);
    }

    public function update(Request $request): TeamResource
    {
        Gate::authorize('manage-team');

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ]);

        $team = Team::findOrFail($request->user()->current_team_id);

        $team->update($request->only(['name', 'settings']));

        return new TeamResource($team);
    }

    /**
     * @response 200 {"data": [{"id": "uuid", "name": "Alice", "email": "alice@example.com", "role": "owner", "joined_at": "2026-01-01T00:00:00.000000Z"}]}
     */
    public function members(Request $request): JsonResponse
    {
        $team = Team::findOrFail($request->user()->current_team_id);

        $members = $team->users->map(fn ($user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->pivot->role,
            'joined_at' => $user->pivot->created_at?->toISOString(),
        ]);

        return response()->json(['data' => $members]);
    }

    /**
     * @response 200 {"message": "Member removed."}
     * @response 403 {"message": "Cannot remove the team owner."}
     */
    public function removeMember(Request $request, string $userId): JsonResponse
    {
        Gate::authorize('manage-team');

        $team = Team::findOrFail($request->user()->current_team_id);

        if ($userId === $team->owner_id) {
            return response()->json(['message' => 'Cannot remove the team owner.'], 403);
        }

        $team->users()->detach($userId);

        return response()->json(['message' => 'Member removed.']);
    }

    /**
     * @response 200 {"data": [{"id": "uuid", "provider": "anthropic", "is_active": true, "created_at": "2026-01-01T00:00:00.000000Z"}]}
     */
    public function credentials(Request $request): JsonResponse
    {
        $credentials = TeamProviderCredential::where('team_id', $request->user()->current_team_id)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'provider' => $c->provider,
                'is_active' => $c->is_active,
                'created_at' => $c->created_at->toISOString(),
            ]);

        return response()->json(['data' => $credentials]);
    }

    /**
     * @response 201 {"data": {"id": "uuid", "provider": "anthropic", "is_active": true}}
     * @response 422 {"message": "Validation error.", "errors": {"provider": ["The provider field is required."]}}
     */
    public function storeCredential(Request $request): JsonResponse
    {
        $request->validate([
            'provider' => ['required', 'string', 'max:50'],
            'credentials' => ['required', 'array'],
            'credentials.api_key' => ['required', 'string'],
        ]);

        $credential = TeamProviderCredential::create([
            'team_id' => $request->user()->current_team_id,
            'provider' => $request->provider,
            'credentials' => $request->credentials,
            'is_active' => true,
        ]);

        return response()->json([
            'data' => [
                'id' => $credential->id,
                'provider' => $credential->provider,
                'is_active' => $credential->is_active,
            ],
        ], 201);
    }

    /**
     * @response 200 {"message": "Credential removed."}
     * @response 403 {"message": "This resource belongs to another team."}
     */
    public function deleteCredential(Request $request, TeamProviderCredential $credential): JsonResponse
    {
        if ($credential->team_id !== $request->user()->current_team_id) {
            abort(403, 'This resource belongs to another team.');
        }

        $credential->delete();

        return response()->json(['message' => 'Credential removed.']);
    }

    /**
     * @response 200 {"data": [{"id": 1, "name": "my-token", "abilities": ["*"], "last_used_at": "2026-02-24T10:00:00.000000Z", "created_at": "2026-01-01T00:00:00.000000Z"}]}
     */
    public function tokens(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()->get()->map(fn ($token) => [
            'id' => $token->id,
            'name' => $token->name,
            'abilities' => $token->abilities,
            'last_used_at' => $token->last_used_at?->toISOString(),
            'created_at' => $token->created_at->toISOString(),
        ]);

        return response()->json(['data' => $tokens]);
    }

    /**
     * @response 201 {"data": {"token": "3|plaintext...", "name": "my-token"}}
     * @response 422 {"message": "Validation error.", "errors": {"name": ["The name field is required."]}}
     */
    public function createToken(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['sometimes', 'array'],
        ]);

        // Force team-scoped ability — reject wildcard or foreign-team abilities.
        $teamAbility = 'team:'.$request->user()->current_team_id;
        $requested = $request->input('abilities', [$teamAbility]);

        if (in_array('*', $requested, true) || array_diff($requested, [$teamAbility])) {
            abort(422, 'Token abilities must be limited to the current team.');
        }

        $token = SanctumTokenIssuer::create($request->user(), $request->name, [$teamAbility]);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'name' => $request->name,
            ],
        ], 201);
    }

    /**
     * @response 200 {"message": "Token revoked."}
     */
    public function revokeToken(Request $request, string $tokenId): JsonResponse
    {
        $request->user()->tokens()->where('id', $tokenId)->delete();

        return response()->json(['message' => 'Token revoked.']);
    }
}
