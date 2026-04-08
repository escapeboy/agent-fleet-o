<?php

namespace App\Domain\Assistant\Tools\Mutations;

use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Signal\Models\ConnectorBinding;
use App\Infrastructure\Auth\SanctumTokenIssuer;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

final class DataMutationTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function writeTools(): array
    {
        return [
            self::approveRequest(),
            self::rejectRequest(),
            self::uploadMemoryKnowledge(),
            self::rejectEvolutionProposal(),
        ];
    }

    /**
     * @return array<PrismToolObject>
     */
    public static function destructiveTools(): array
    {
        return [
            self::deleteMemory(),
            self::deleteConnectorBinding(),
            self::manageByokCredential(),
            self::manageApiToken(),
        ];
    }

    public static function approveRequest(): PrismToolObject
    {
        return PrismTool::as('approve_request')
            ->for('Approve a pending approval request')
            ->withStringParameter('approval_id', 'The approval request UUID', required: true)
            ->withStringParameter('notes', 'Optional approval notes')
            ->using(function (string $approval_id, ?string $notes = null) {
                $approval = ApprovalRequest::find($approval_id);
                if (! $approval) {
                    return json_encode(['error' => 'Approval request not found']);
                }

                try {
                    app(ApproveAction::class)->execute($approval, auth()->id(), $notes);

                    return json_encode(['success' => true, 'message' => 'Request approved.']);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function rejectRequest(): PrismToolObject
    {
        return PrismTool::as('reject_request')
            ->for('Reject a pending approval request')
            ->withStringParameter('approval_id', 'The approval request UUID', required: true)
            ->withStringParameter('reason', 'Reason for rejection', required: true)
            ->withStringParameter('notes', 'Optional rejection notes')
            ->using(function (string $approval_id, string $reason, ?string $notes = null) {
                $approval = ApprovalRequest::find($approval_id);
                if (! $approval) {
                    return json_encode(['error' => 'Approval request not found']);
                }

                try {
                    app(RejectAction::class)->execute($approval, auth()->id(), $reason, $notes);

                    return json_encode(['success' => true, 'message' => 'Request rejected.']);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function uploadMemoryKnowledge(): PrismToolObject
    {
        return PrismTool::as('upload_memory_knowledge')
            ->for('Store a new knowledge item in memory. Useful for injecting domain knowledge or reference material that agents can recall.')
            ->withStringParameter('content', 'The knowledge content to store', required: true)
            ->withStringParameter('agent_id', 'Optional agent UUID to associate this memory with')
            ->withStringParameter('source_type', 'Source category label (default: manual_upload)')
            ->using(function (string $content, ?string $agent_id = null, ?string $source_type = null) {
                $teamId = auth()->user()?->current_team_id;

                if (! $teamId) {
                    return json_encode(['error' => 'No current team.']);
                }

                try {
                    $memory = Memory::create([
                        'team_id' => $teamId,
                        'agent_id' => $agent_id,
                        'content' => trim($content),
                        'source_type' => $source_type ?? 'manual_upload',
                        'metadata' => ['uploaded_by' => auth()->id(), 'uploaded_at' => now()->toIso8601String()],
                    ]);

                    return json_encode([
                        'success' => true,
                        'memory_id' => $memory->id,
                        'source_type' => $memory->source_type,
                        'content_length' => strlen($memory->content),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function deleteMemory(): PrismToolObject
    {
        return PrismTool::as('delete_memory')
            ->for('Delete one or more memory records by UUID. Only memories belonging to the current team can be deleted. This is destructive.')
            ->withStringParameter('memory_ids', 'Comma-separated memory UUIDs (or JSON array) to delete', required: true)
            ->using(function (string $memory_ids) {
                $ids = json_decode($memory_ids, true) ?? array_filter(array_map('trim', explode(',', $memory_ids)));
                $teamId = auth()->user()?->current_team_id;

                if (! $teamId) {
                    return json_encode(['error' => 'No current team.']);
                }

                try {
                    $deleted = Memory::withoutGlobalScopes()
                        ->where('team_id', $teamId)
                        ->whereIn('id', $ids)
                        ->delete();

                    return json_encode([
                        'success' => true,
                        'deleted_count' => $deleted,
                        'message' => "{$deleted} memory record(s) deleted.",
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function rejectEvolutionProposal(): PrismToolObject
    {
        return PrismTool::as('reject_evolution_proposal')
            ->for('Reject a pending or approved evolution proposal, preventing it from being applied to the agent.')
            ->withStringParameter('proposal_id', 'The evolution proposal UUID', required: true)
            ->withStringParameter('reason', 'Optional reason for rejection')
            ->using(function (string $proposal_id, ?string $reason = null) {
                $proposal = EvolutionProposal::find($proposal_id);
                if (! $proposal) {
                    return json_encode(['error' => 'Evolution proposal not found']);
                }

                if (! in_array($proposal->status, [EvolutionProposalStatus::Pending, EvolutionProposalStatus::Approved])) {
                    return json_encode(['error' => "Cannot reject proposal in '{$proposal->status->value}' status."]);
                }

                try {
                    $proposal->update([
                        'status' => EvolutionProposalStatus::Rejected,
                        'reviewed_by' => auth()->id(),
                        'reviewed_at' => now(),
                    ]);

                    return json_encode([
                        'success' => true,
                        'proposal_id' => $proposal->id,
                        'status' => 'rejected',
                        'reason' => $reason,
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function deleteConnectorBinding(): PrismToolObject
    {
        return PrismTool::as('delete_connector_binding')
            ->for('Delete a connector binding (DM pairing / sender approval). This will prevent the sender from communicating via this channel. Destructive.')
            ->withStringParameter('binding_id', 'The connector binding UUID', required: true)
            ->using(function (string $binding_id) {
                $teamId = auth()->user()?->current_team_id;

                if (! $teamId) {
                    return json_encode(['error' => 'No current team.']);
                }

                $binding = ConnectorBinding::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->find($binding_id);

                if (! $binding) {
                    return json_encode(['error' => 'Connector binding not found.']);
                }

                try {
                    $channel = $binding->channel;
                    $externalName = $binding->external_name ?? $binding->external_id;
                    $binding->delete();

                    return json_encode([
                        'success' => true,
                        'binding_id' => $binding_id,
                        'message' => "Binding for '{$externalName}' on {$channel} deleted.",
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function manageByokCredential(): PrismToolObject
    {
        return PrismTool::as('manage_byok_credential')
            ->for('Manage BYOK (Bring Your Own Key) LLM API credentials. List configured providers, set an API key, or delete a provider key. SECURITY: API keys are never returned after storage.')
            ->withStringParameter('action', 'Action: list, set, delete', required: true)
            ->withStringParameter('provider', 'LLM provider name (e.g. anthropic, openai, google). Required for set/delete.')
            ->withStringParameter('api_key', 'The API key to store securely (for set action only). Will be encrypted.')
            ->using(function (string $action, ?string $provider = null, ?string $api_key = null) {
                $teamId = auth()->user()?->current_team_id;

                if (! $teamId) {
                    return json_encode(['error' => 'No current team.']);
                }

                if ($action === 'list') {
                    $creds = TeamProviderCredential::where('team_id', $teamId)
                        ->get(['id', 'provider', 'is_active', 'updated_at'])
                        ->map(fn ($c) => [
                            'provider' => $c->provider,
                            'is_active' => $c->is_active,
                            'configured_at' => $c->updated_at?->toIso8601String(),
                            'note' => 'API key stored encrypted, cannot be retrieved.',
                        ]);

                    return json_encode(['providers' => $creds->toArray()]);
                }

                if ($action === 'set') {
                    if (! $provider || ! $api_key) {
                        return json_encode(['error' => 'provider and api_key are required for set action.']);
                    }

                    TeamProviderCredential::updateOrCreate(
                        ['team_id' => $teamId, 'provider' => $provider],
                        ['credentials' => ['api_key' => $api_key], 'is_active' => true],
                    );

                    $masked = strlen($api_key) > 8
                        ? str_repeat('*', strlen($api_key) - 4).substr($api_key, -4)
                        : '****';

                    return json_encode([
                        'success' => true,
                        'provider' => $provider,
                        'masked_key' => $masked,
                        'message' => "API key for '{$provider}' stored securely. Will not be shown again.",
                    ]);
                }

                if ($action === 'delete') {
                    if (! $provider) {
                        return json_encode(['error' => 'provider is required for delete action.']);
                    }

                    $deleted = TeamProviderCredential::where('team_id', $teamId)
                        ->where('provider', $provider)
                        ->delete();

                    if (! $deleted) {
                        return json_encode(['error' => "No credential found for provider '{$provider}'."]);
                    }

                    return json_encode(['success' => true, 'provider' => $provider, 'message' => "API key for '{$provider}' deleted."]);
                }

                return json_encode(['error' => "Unknown action: {$action}. Use list, set, or delete."]);
            });
    }

    public static function manageApiToken(): PrismToolObject
    {
        return PrismTool::as('manage_api_token')
            ->for('Manage Sanctum API tokens for the current user. List tokens, create a new one (shown ONCE), or revoke by ID. Destructive for revoke.')
            ->withStringParameter('action', 'Action: list, create, revoke', required: true)
            ->withStringParameter('name', 'Token name/label (required for create)')
            ->withStringParameter('token_id', 'Token ID to revoke (required for revoke action)')
            ->using(function (string $action, ?string $name = null, ?string $token_id = null) {
                $user = auth()->user();

                if (! $user) {
                    return json_encode(['error' => 'Not authenticated.']);
                }

                if ($action === 'list') {
                    $tokens = $user->sanctumTokens()
                        ->get(['id', 'name', 'last_used_at', 'expires_at', 'created_at'])
                        ->map(fn ($t) => [
                            'id' => $t->id,
                            'name' => $t->name,
                            'last_used_at' => $t->last_used_at?->toIso8601String(),
                            'expires_at' => $t->expires_at?->toIso8601String(),
                            'created_at' => $t->created_at->toIso8601String(),
                        ]);

                    return json_encode(['count' => $tokens->count(), 'tokens' => $tokens->toArray()]);
                }

                if ($action === 'create') {
                    if (! $name) {
                        return json_encode(['error' => 'name is required for create action.']);
                    }

                    $abilities = $user->is_super_admin ? ['*'] : ['team:'.$user->current_team_id];
                    $expiresAt = now()->addDays(90);
                    $token = SanctumTokenIssuer::create($user, $name, $abilities, $expiresAt);

                    return json_encode([
                        'success' => true,
                        'token_id' => $token->accessToken->id,
                        'name' => $name,
                        'token' => $token->plainTextToken,
                        'expires_at' => $expiresAt->toIso8601String(),
                        'warning' => 'This token will not be shown again. Store it securely.',
                    ]);
                }

                if ($action === 'revoke') {
                    if (! $token_id) {
                        return json_encode(['error' => 'token_id is required for revoke action.']);
                    }

                    $deleted = $user->sanctumTokens()->where('id', $token_id)->delete();

                    if (! $deleted) {
                        return json_encode(['error' => "Token {$token_id} not found."]);
                    }

                    return json_encode(['success' => true, 'token_id' => $token_id, 'message' => "Token {$token_id} revoked."]);
                }

                return json_encode(['error' => "Unknown action: {$action}. Use list, create, or revoke."]);
            });
    }
}
