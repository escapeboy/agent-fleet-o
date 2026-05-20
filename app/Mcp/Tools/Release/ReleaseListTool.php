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
class ReleaseListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'release_list';

    protected string $description = 'List releases for the current team. Optional filter by status (draft, published, archived).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Optional status filter: draft|published|archived'),
            'limit' => $schema->integer()
                ->description('Max releases to return (default 50, max 200)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'status' => 'nullable|in:draft,published,archived',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $query = Release::query()->orderByDesc('created_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $releases = $query->limit($validated['limit'] ?? 50)->get();

        return Response::text(json_encode([
            'releases' => $releases->map(fn (Release $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'version' => $r->version,
                'status' => $r->status->value,
                'share_token' => $r->share_token,
                'published_at' => $r->published_at?->toIso8601String(),
                'archived_at' => $r->archived_at?->toIso8601String(),
                'created_at' => $r->created_at?->toIso8601String(),
            ])->toArray(),
            'count' => $releases->count(),
        ]));
    }
}
