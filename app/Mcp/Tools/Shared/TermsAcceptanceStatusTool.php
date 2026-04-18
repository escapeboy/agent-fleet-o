<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Services\TermsAcceptanceService;
use App\Mcp\Attributes\AssistantTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class TermsAcceptanceStatusTool extends Tool
{
    protected string $name = 'terms_acceptance_status';

    protected string $description = 'Get the current terms acceptance status for a user. Returns the current terms version, the user\'s accepted version, and whether acceptance is required.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->string()->description('User UUID to check. Defaults to the authenticated user.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $userId = $request->get('user_id');

        if ($userId) {
            $user = User::find($userId);
            if (! $user) {
                return Response::error('User not found.');
            }
        } else {
            $user = auth()->user();
            if (! $user) {
                return Response::error('No authenticated user.');
            }
        }

        $service = app(TermsAcceptanceService::class);
        $currentVersion = (int) config('terms.current_version');

        return Response::text(json_encode([
            'user_id' => $user->id,
            'current_terms_version' => $currentVersion,
            'user_terms_version' => $user->terms_version,
            'terms_accepted_at' => $user->terms_accepted_at?->toIso8601String(),
            'requires_acceptance' => $service->requiresAcceptance($user),
            'enforcement_enabled' => $currentVersion > 0,
        ]));
    }
}
