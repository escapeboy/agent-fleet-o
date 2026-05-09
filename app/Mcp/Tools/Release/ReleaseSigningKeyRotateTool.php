<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Release;

use App\Domain\Release\Crypto\Actions\RotateSigningKeyAction;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ReleaseSigningKeyRotateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'release_signing_key_rotate';

    protected string $description = 'Rotate the team\'s active signing key. Existing key transitions to grace (90-day window) and a new key is generated.';

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

        $key = app(RotateSigningKeyAction::class)->execute($teamId);

        return Response::text(json_encode([
            'kid' => $key->id,
            'public_key' => $key->public_key,
            'status' => $key->status->value,
            'rotated_at' => now()->toIso8601String(),
        ]));
    }
}
