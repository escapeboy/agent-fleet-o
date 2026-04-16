<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Framework;

use App\Domain\Skill\Enums\Framework;
use App\Domain\Skill\Enums\FrameworkCategory;
use App\Domain\Skill\Models\Skill;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class FrameworkListTool extends Tool
{
    protected string $name = 'framework_list';

    protected string $description = 'List the 20 curated Skill frameworks (RICE, SPIN, BANT, OKRs, etc.) with category and per-team skill counts. Use to discover which methodology tags are available when tagging skills.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()
                ->description('Filter by category: validation, sales, growth, finance, engineering, operations')
                ->enum(['validation', 'sales', 'growth', 'finance', 'engineering', 'operations']),
        ];
    }

    public function handle(Request $request): Response
    {
        $categoryFilter = $request->get('category');
        $selected = $categoryFilter !== null ? FrameworkCategory::tryFrom($categoryFilter) : null;

        $teamId = app('mcp.team_id') ?? Auth::user()?->current_team_id;

        $counts = [];
        if ($teamId !== null) {
            $counts = Skill::query()
                ->where('team_id', $teamId)
                ->whereNotNull('framework')
                ->selectRaw('framework, count(*) as total')
                ->groupBy('framework')
                ->pluck('total', 'framework')
                ->toArray();
        }

        $frameworks = collect(Framework::cases())
            ->when($selected, fn ($c) => $c->filter(fn (Framework $f) => $f->category() === $selected))
            ->map(fn (Framework $f) => [
                'key' => $f->value,
                'label' => $f->label(),
                'description' => $f->description(),
                'category' => $f->category()->value,
                'skill_count' => (int) ($counts[$f->value] ?? 0),
            ])
            ->values()
            ->all();

        return Response::text(json_encode([
            'count' => count($frameworks),
            'categories' => array_map(fn (FrameworkCategory $c) => ['key' => $c->value, 'label' => $c->label()], FrameworkCategory::cases()),
            'frameworks' => $frameworks,
        ], JSON_UNESCAPED_SLASHES));
    }
}
