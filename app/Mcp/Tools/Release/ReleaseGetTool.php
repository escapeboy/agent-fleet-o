<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Release;

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
class ReleaseGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'release_get';

    protected string $description = 'Get a single release with its attached artifacts.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'release_id' => $schema->string()->description('Release UUID')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['release_id' => 'required|string']);

        $release = Release::with(['artifacts'])->find($validated['release_id']);

        if (! $release) {
            return $this->notFoundError('release');
        }

        return Response::text(json_encode([
            'id' => $release->id,
            'name' => $release->name,
            'slug' => $release->slug,
            'version' => $release->version,
            'notes' => $release->notes,
            'status' => $release->status->value,
            'share_token' => $release->share_token,
            'metadata' => $release->metadata,
            'published_at' => $release->published_at?->toIso8601String(),
            'archived_at' => $release->archived_at?->toIso8601String(),
            'created_at' => $release->created_at?->toIso8601String(),
            'artifacts' => $release->artifacts->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'pivot_version' => $a->pivot->artifact_version,
                'sort_order' => $a->pivot->sort_order,
            ])->toArray(),
        ]));
    }
}
