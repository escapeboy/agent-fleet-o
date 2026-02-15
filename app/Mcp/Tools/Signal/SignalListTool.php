<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Models\Signal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class SignalListTool extends Tool
{
    protected string $name = 'signal_list';

    protected string $description = 'List signals ordered by most recent. Returns id, source, payload summary, created_at.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $limit = min((int) ($request->get('limit', 10)), 100);

        $signals = Signal::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return Response::text(json_encode([
            'count' => $signals->count(),
            'signals' => $signals->map(fn ($s) => [
                'id' => $s->id,
                'source' => $s->source_type,
                'payload' => mb_substr(json_encode($s->payload ?? []), 0, 200),
                'created_at' => $s->created_at?->diffForHumans(),
            ])->toArray(),
        ]));
    }
}
