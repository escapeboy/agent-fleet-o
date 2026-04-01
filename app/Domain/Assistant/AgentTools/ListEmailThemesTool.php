<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Email\Models\EmailTheme;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ListEmailThemesTool implements Tool
{
    public function name(): string
    {
        return 'list_email_themes';
    }

    public function description(): string
    {
        return 'List email themes for the current team';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Filter by status: draft, active, archived'),
            'limit' => $schema->integer()->description('Max results to return (default 10)'),
        ];
    }

    public function handle(Request $request): string
    {
        $query = EmailTheme::query()->orderBy('name');

        if ($request->get('status')) {
            $query->where('status', $request->get('status'));
        }

        $themes = $query->limit($request->get('limit', 10))->get();

        return json_encode([
            'count' => $themes->count(),
            'themes' => $themes->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'status' => $t->status->value,
                'primary_color' => $t->primary_color,
                'font_name' => $t->font_name,
            ])->toArray(),
        ]);
    }
}
