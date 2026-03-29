<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Credential\Actions\CreateCredentialAction;
use App\Domain\Credential\Actions\DeleteCredentialAction;
use App\Domain\Credential\Actions\RollbackCredentialVersionAction;
use App\Domain\Credential\Actions\RotateCredentialSecretAction;
use App\Domain\Credential\Actions\UpdateCredentialAction;
use App\Domain\Credential\Enums\CredentialSource;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Credential\Models\CredentialVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCredentialRequest;
use App\Http\Resources\Api\V1\CredentialResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Credentials
 */
class CredentialController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $credentials = QueryBuilder::for(Credential::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('credential_type'),
                AllowedFilter::exact('creator_source'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['created_at', 'updated_at', 'name', 'last_used_at'])
            ->defaultSort('-created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return CredentialResource::collection($credentials);
    }

    public function show(Credential $credential): CredentialResource
    {
        return new CredentialResource($credential);
    }

    public function store(StoreCredentialRequest $request, CreateCredentialAction $action): JsonResponse
    {
        $creatorSource = CredentialSource::tryFrom($request->input('creator_source', 'human'))
            ?? CredentialSource::Human;

        $creatorType = null;
        $creatorId = null;
        if ($creatorSource === CredentialSource::Agent && $request->input('agent_id')) {
            $creatorType = 'agent';
            $creatorId = $request->input('agent_id');
        }

        $credential = $action->execute(
            teamId: $request->user()->current_team_id,
            name: $request->name,
            credentialType: CredentialType::from($request->credential_type),
            secretData: $request->input('secret_data'),
            description: $request->input('description'),
            metadata: $request->input('metadata', []),
            expiresAt: $request->input('expires_at'),
            creatorSource: $creatorSource,
            creatorType: $creatorType,
            creatorId: $creatorId,
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

    /**
     * @response 200 {"message": "Credential deleted."}
     */
    public function destroy(Credential $credential, DeleteCredentialAction $action): JsonResponse
    {
        $action->execute($credential);

        return response()->json(['message' => 'Credential deleted.']);
    }

    public function rotate(Request $request, Credential $credential, RotateCredentialSecretAction $action): CredentialResource
    {
        $request->validate([
            'secret_data' => ['required', 'array'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $action->execute(
            $credential,
            $request->input('secret_data'),
            $request->input('note'),
            $request->user()?->id,
        );

        return new CredentialResource($credential->refresh());
    }

    /**
     * List version history for a credential (secret_data excluded).
     *
     * @response 200 array<{id: string, version_number: int, note: string|null, created_at: string}>
     */
    public function versions(Credential $credential): JsonResponse
    {
        $versions = CredentialVersion::withoutGlobalScopes()
            ->where('credential_id', $credential->id)
            ->orderByDesc('version_number')
            ->get(['id', 'version_number', 'note', 'created_by', 'created_at']);

        return response()->json(['data' => $versions]);
    }

    /**
     * Rollback a credential to a previous version's secret_data.
     */
    public function rollback(
        Request $request,
        Credential $credential,
        CredentialVersion $version,
        RollbackCredentialVersionAction $action,
    ): CredentialResource {
        // Ensure the version belongs to this credential (scoped within team via global scope).
        abort_if($version->credential_id !== $credential->id, 404);

        $action->execute($credential, $version, $request->user()?->id);

        return new CredentialResource($credential->refresh());
    }
}
