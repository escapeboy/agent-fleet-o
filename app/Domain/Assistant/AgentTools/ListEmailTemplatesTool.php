<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Email\Models\EmailTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ListEmailTemplatesTool implements Tool
{
    public function name(): string
    {
        return 'list_email_templates';
    }

    public function description(): string
    {
        return 'List email templates with optional status or visibility filter';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Filter by status: draft, active, archived'),
            'visibility' => $schema->string()->description('Filter by visibility: private, public'),
            'limit' => $schema->integer()->description('Max results to return (default 10)'),
        ];
    }

    public function handle(Request $request): string
    {
        $query = EmailTemplate::query()->orderByDesc('updated_at');

        if ($request->get('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->get('visibility')) {
            $query->where('visibility', $request->get('visibility'));
        }

        $templates = $query->limit($request->get('limit', 10))->get();

        return json_encode([
            'count' => $templates->count(),
            'templates' => $templates->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'subject' => $t->subject,
                'status' => $t->status->value,
                'visibility' => $t->visibility->value,
                'has_html_cache' => ! empty($t->html_cache),
                'updated' => $t->updated_at->diffForHumans(),
            ])->toArray(),
        ]);
    }
}
