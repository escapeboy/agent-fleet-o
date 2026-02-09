<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TeamResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function show(Request $request): TeamResource
    {
        $team = Team::withCount('users')->findOrFail($request->user()->current_team_id);

        return new TeamResource($team);
    }

    public function update(Request $request): TeamResource
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ]);

        $team = Team::findOrFail($request->user()->current_team_id);

        $team->update($request->only(['name', 'settings']));

        return new TeamResource($team);
    }

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

    public function removeMember(Request $request, string $userId): JsonResponse
    {
        $team = Team::findOrFail($request->user()->current_team_id);

        if ($userId === $team->owner_id) {
            return response()->json(['message' => 'Cannot remove the team owner.'], 403);
        }

        $team->users()->detach($userId);

        return response()->json(['message' => 'Member removed.']);
    }

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

    public function deleteCredential(TeamProviderCredential $credential): JsonResponse
    {
        $credential->delete();

        return response()->json(['message' => 'Credential removed.']);
    }

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

    public function createToken(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['sometimes', 'array'],
        ]);

        $token = $request->user()->createToken(
            $request->name,
            $request->input('abilities', ['*']),
        );

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'name' => $request->name,
            ],
        ], 201);
    }

    public function revokeToken(Request $request, string $tokenId): JsonResponse
    {
        $request->user()->tokens()->where('id', $tokenId)->delete();

        return response()->json(['message' => 'Token revoked.']);
    }
}
