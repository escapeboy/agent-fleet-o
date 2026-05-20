<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Release;

use App\Domain\Release\Crypto\Actions\VerifyReleaseSignatureAction;
use App\Domain\Release\Models\Release;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ReleaseVerifySignatureTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'release_verify_signature';

    protected string $description = 'Verify the cryptographic signature on a release. Returns verification status, signing kid, and dual-sig fallback info.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'release_id' => $schema->string()->description('Release UUID')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['release_id' => 'required|string']);

        $release = Release::find($validated['release_id']);
        if (! $release) {
            return $this->notFoundError('release');
        }

        $result = app(VerifyReleaseSignatureAction::class)->execute($release);

        $signedAge = $release->signed_at ? (int) $release->signed_at->diffInDays() : null;

        return Response::text(json_encode([
            'release_id' => $release->id,
            'verified' => in_array($result['status'], ['verified', 'verified_grace'], true),
            'status' => $result['status'],
            'kid' => $result['kid'],
            'via' => $result['via'],
            'signature_age_days' => $signedAge,
            'signed_at' => $release->signed_at?->toIso8601String(),
        ]));
    }
}
