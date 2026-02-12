<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Credential\Actions\CreateCredentialAction;
use App\Domain\Credential\Actions\DeleteCredentialAction;
use App\Domain\Credential\Actions\RotateCredentialSecretAction;
use App\Domain\Credential\Actions\UpdateCredentialAction;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CredentialResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CredentialController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $credentials = QueryBuilder::for(Credential::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('credential_type'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['created_at', 'updated_at', 'name', 'last_used_at'])
            ->defaultSort('-created_at')
            ->cursorPaginate($request->input('per_page', 15));

        return CredentialResource::collection($credentials);
    }

    public function show(Credential $credential): CredentialResource
    {
        return new CredentialResource($credential);
    }

    public function store(Request $request, CreateCredentialAction $action): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'credential_type' => ['required', new Enum(CredentialType::class)],
            'secret_data' => ['required', 'array'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $credential = $action->execute(
            teamId: $request->user()->current_team_id,
            name: $request->name,
            credentialType: CredentialType::from($request->credential_type),
            secretData: $request->input('secret_data'),
            description: $request->input('description'),
            metadata: $request->input('metadata', []),
            expiresAt: $request->input('expires_at'),
        );

        return (new CredentialResource($credential))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Credential $credential, UpdateCredentialAction $action): CredentialResource
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', new Enum(CredentialStatus::class)],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $action->execute(
            $credential,
            name: $request->input('name'),
            description: $request->input('description'),
            status: $request->has('status') ? CredentialStatus::from($request->status) : null,
            metadata: $request->input('metadata'),
            expiresAt: $request->input('expires_at'),
        );

        return new CredentialResource($credential->refresh());
    }

    public function destroy(Credential $credential, DeleteCredentialAction $action): JsonResponse
    {
        $action->execute($credential);

        return response()->json(['message' => 'Credential deleted.']);
    }

    public function rotate(Request $request, Credential $credential, RotateCredentialSecretAction $action): CredentialResource
    {
        $request->validate([
            'secret_data' => ['required', 'array'],
        ]);

        $action->execute($credential, $request->input('secret_data'));

        return new CredentialResource($credential->refresh());
    }
}
