<?php

namespace App\Mcp\Tools\Email;

use App\Domain\Email\Models\EmailTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class EmailTemplateListTool extends Tool
{
    protected string $name = 'email_template_list';

    protected string $description = 'List email templates for the current team. Returns id, name, subject, status, and visibility.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: draft, active, archived')
                ->enum(['draft', 'active', 'archived']),
            'visibility' => $schema->string()
                ->description('Filter by visibility: private, public')
                ->enum(['private', 'public']),
            'limit' => $schema->integer()
                ->description('Max results (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = EmailTemplate::query()->orderBy('name');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($visibility = $request->get('visibility')) {
            $query->where('visibility', $visibility);
        }

        $limit = min((int) ($request->get('limit', 10)), 100);
        $templates = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $templates->count(),
            'templates' => $templates->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'subject' => $t->subject,
                'status' => $t->status->value,
                'visibility' => $t->visibility->value,
                'email_theme_id' => $t->email_theme_id,
                'has_html_cache' => ! empty($t->html_cache),
                'updated_at' => $t->updated_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
