<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Release;

use App\Domain\Release\Crypto\Actions\GenerateSigningKeyAction;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsDestructive]
#[IsIdempotent]
class ReleaseSigningKeyGenerateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'release_signing_key_generate';

    protected string $description = 'Generate a new Ed25519 signing key for the team. Idempotent: returns existing active key if one exists.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = (string) (auth()->user()->current_team_id ?? '');
        if ($teamId === '') {
            return $this->validationError('current_team_id missing on user');
        }

        $key = app(GenerateSigningKeyAction::class)->execute($teamId);

        return Response::text(json_encode([
            'kid' => $key->id,
            'public_key' => $key->public_key,
            'status' => $key->status->value,
            'created_at' => $key->created_at?->toIso8601String(),
        ]));
    }
}
