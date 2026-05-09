<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Release;

use App\Domain\Release\Crypto\Models\ReleaseSigningKey;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ReleaseSigningKeyListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'release_signing_key_list';

    protected string $description = 'List release signing keys for the current team. Secret material is never returned.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $keys = ReleaseSigningKey::orderBy('created_at')->get();

        return Response::text(json_encode([
            'keys' => $keys->map(fn (ReleaseSigningKey $k) => [
                'kid' => $k->id,
                'public_key' => $k->public_key,
                'status' => $k->status->value,
                'rotated_at' => $k->rotated_at?->toIso8601String(),
                'revoked_at' => $k->revoked_at?->toIso8601String(),
                'grace_expires_at' => $k->grace_expires_at?->toIso8601String(),
                'created_at' => $k->created_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
