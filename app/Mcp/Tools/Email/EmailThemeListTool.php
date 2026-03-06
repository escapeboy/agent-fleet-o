<?php

namespace App\Mcp\Tools\Email;

use App\Domain\Email\Models\EmailTheme;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class EmailThemeListTool extends Tool
{
    protected string $name = 'email_theme_list';

    protected string $description = 'List email themes for the current team. Returns id, name, status, primary_color, and font_name.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: draft, active, archived')
                ->enum(['draft', 'active', 'archived']),
            'limit' => $schema->integer()
                ->description('Max results (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = EmailTheme::query()->orderBy('name');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $limit = min((int) ($request->get('limit', 10)), 100);
        $themes = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $themes->count(),
            'themes' => $themes->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'status' => $t->status->value,
                'primary_color' => $t->primary_color,
                'font_name' => $t->font_name,
                'logo_url' => $t->logo_url,
            ])->toArray(),
        ]));
    }
}
