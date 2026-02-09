<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\UpdateMeRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Issue a new API token (login).
     */
    public function token(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $deviceName = $request->input('device_name', 'api-client');

        $token = $user->createToken($deviceName, ['*'], now()->addDays(30));

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toISOString(),
            'user' => new UserResource($user->load('currentTeam')),
        ], 201);
    }

    /**
     * Refresh current token (revoke old, issue new).
     */
    public function refresh(Request $request): JsonResponse
    {
        $currentToken = $request->user()->currentAccessToken();
        $deviceName = $currentToken->name;

        $newToken = $request->user()->createToken($deviceName, ['*'], now()->addDays(30));

        $currentToken->delete();

        return response()->json([
            'token' => $newToken->plainTextToken,
            'expires_at' => $newToken->accessToken->expires_at?->toISOString(),
        ]);
    }

    /**
     * Revoke current token (logout).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Token revoked.']);
    }

    /**
     * List all active tokens/devices for current user.
     */
    public function devices(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toISOString(),
                'expires_at' => $token->expires_at?->toISOString(),
                'created_at' => $token->created_at->toISOString(),
                'is_current' => $token->id === $request->user()->currentAccessToken()->id,
            ]);

        return response()->json(['data' => $tokens]);
    }

    /**
     * Revoke a specific token by ID.
     */
    public function revokeDevice(Request $request, int $tokenId): JsonResponse
    {
        $token = $request->user()->tokens()->where('id', $tokenId)->first();

        if (! $token) {
            abort(404, 'Token not found.');
        }

        $token->delete();

        return response()->json(['message' => 'Token revoked.']);
    }

    /**
     * Get current authenticated user profile.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('currentTeam'));
    }

    /**
     * Update current user profile.
     */
    public function updateMe(UpdateMeRequest $request): UserResource
    {
        $user = $request->user();

        $data = $request->only(['name', 'email']);

        if ($request->filled('password')) {
            $data['password'] = $request->password;
        }

        $user->update($data);

        return new UserResource($user->fresh()->load('currentTeam'));
    }
}
