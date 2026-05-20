<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Release;

use App\Domain\Release\Crypto\Actions\RevokeSigningKeyAction;
use App\Domain\Release\Crypto\Models\ReleaseSigningKey;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ReleaseSigningKeyRevokeTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'release_signing_key_revoke';

    protected string $description = 'Immediately revoke a signing key (compromise scenario). No grace period — all releases signed by it will fail verification.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'kid' => $schema->string()->description('Signing key UUID')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['kid' => 'required|string']);

        $key = ReleaseSigningKey::find($validated['kid']);
        if (! $key) {
            return $this->notFoundError('signing_key');
        }

        $key = app(RevokeSigningKeyAction::class)->execute($key);

        return Response::text(json_encode([
            'kid' => $key->id,
            'status' => $key->status->value,
            'revoked_at' => $key->revoked_at?->toIso8601String(),
        ]));
    }
}
