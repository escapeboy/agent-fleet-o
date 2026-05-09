<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Release;

use App\Domain\Release\Actions\PublishReleaseAction;
use App\Domain\Release\Models\Release;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ReleasePublishTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'release_publish';

    protected string $description = 'Publish a draft release. Generates a share token; idempotent on already-published releases.';

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

        try {
            $release = app(PublishReleaseAction::class)->execute($release);
        } catch (InvalidArgumentException $e) {
            return $this->validationError($e->getMessage());
        }

        return Response::text(json_encode([
            'id' => $release->id,
            'status' => $release->status->value,
            'share_token' => $release->share_token,
            'published_at' => $release->published_at?->toIso8601String(),
        ]));
    }
}
