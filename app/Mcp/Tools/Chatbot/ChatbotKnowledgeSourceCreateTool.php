<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Actions\CreateKnowledgeSourceAction;
use App\Domain\Chatbot\Models\Chatbot;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ChatbotKnowledgeSourceCreateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'chatbot_knowledge_source_create';

    protected string $description = 'Add a knowledge source to a chatbot knowledge base and queue it for indexing. Supports url (single page), website (full crawl via webclaw), sitemap, document (by storage path), and git_repository types.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()
                ->description('UUID of the chatbot')
                ->required(),
            'name' => $schema->string()
                ->description('Display name for the knowledge source')
                ->required(),
            'type' => $schema->string()
                ->description('Source type: url | website | sitemap | document | git_repository')
                ->enum(['url', 'website', 'sitemap', 'document', 'git_repository'])
                ->required(),
            'source_url' => $schema->string()
                ->description('URL for url / website / sitemap / git_repository types'),
            'max_pages' => $schema->integer()
                ->description('Maximum pages to crawl for website type (1–100, default 30)'),
            'branch' => $schema->string()
                ->description('Branch for git_repository type (default: main)'),
            'storage_path' => $schema->string()
                ->description('Storage path for document type (from a prior file upload)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id');
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $params = $request->get();

        $chatbot = Chatbot::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($params['chatbot_id'] ?? null);

        if (! $chatbot) {
            return $this->notFoundError('Chatbot not found.');
        }

        $type = $params['type'] ?? null;
        $validTypes = ['url', 'website', 'sitemap', 'document', 'git_repository'];

        if (! in_array($type, $validTypes, true)) {
            return $this->invalidArgumentError('type must be one of: '.implode(', ', $validTypes));
        }

        $data = [
            'name' => $params['name'] ?? null,
            'type' => $type,
        ];

        if (! $data['name']) {
            return $this->invalidArgumentError('name is required.');
        }

        if ($type === 'document') {
            $path = $params['storage_path'] ?? null;
            if (! $path) {
                return $this->invalidArgumentError('storage_path is required for document type.');
            }
            $data['source_data'] = ['path' => $path];
        } elseif ($type === 'git_repository') {
            $url = $params['source_url'] ?? null;
            if (! $url) {
                return $this->invalidArgumentError('source_url is required for git_repository type.');
            }
            $data['source_url'] = $url;
            $data['source_data'] = ['branch' => $params['branch'] ?? 'main'];
        } elseif ($type === 'website') {
            $url = $params['source_url'] ?? null;
            if (! $url) {
                return $this->invalidArgumentError('source_url is required for website type.');
            }
            $maxPages = isset($params['max_pages']) ? (int) $params['max_pages'] : 30;
            $maxPages = max(1, min(100, $maxPages));
            $data['source_url'] = $url;
            $data['source_data'] = ['max_pages' => $maxPages];
        } else {
            $url = $params['source_url'] ?? null;
            if (! $url) {
                return $this->invalidArgumentError('source_url is required for '.$type.' type.');
            }
            $data['source_url'] = $url;
        }

        $source = app(CreateKnowledgeSourceAction::class)->execute($chatbot, $data);

        return Response::text(json_encode([
            'success' => true,
            'source_id' => $source->id,
            'name' => $source->name,
            'type' => $source->type->value,
            'status' => $source->status->value,
            'note' => 'Source queued for indexing.',
        ]));
    }
}
