<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\TermsAcceptance;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
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
class TermsAcceptanceHistoryTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'terms_acceptance_history';

    protected string $description = 'Get the full terms acceptance audit log for a user. Returns all versions the user has accepted, with timestamps and metadata.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->string()->description('User UUID. Defaults to the authenticated user.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $userId = $request->get('user_id');

        if ($userId) {
            $user = User::find($userId);
            if (! $user) {
                return $this->notFoundError('user');
            }
        } else {
            $user = auth()->user();
            if (! $user) {
                return $this->permissionDeniedError('No authenticated user.');
            }
        }

        $acceptances = TermsAcceptance::where('user_id', $user->id)
            ->orderBy('accepted_at', 'desc')
            ->get()
            ->map(fn (TermsAcceptance $a) => [
                'id' => $a->id,
                'version' => $a->version,
                'accepted_at' => $a->accepted_at->toIso8601String(),
                'acceptance_method' => $a->acceptance_method,
                'ip_address' => $a->ip_address,
            ]);

        return Response::text(json_encode([
            'user_id' => $user->id,
            'total_acceptances' => $acceptances->count(),
            'history' => $acceptances,
        ]));
    }
}
