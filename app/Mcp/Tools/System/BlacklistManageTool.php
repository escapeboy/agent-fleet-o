<?php

namespace App\Mcp\Tools\System;

use App\Models\Blacklist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class BlacklistManageTool extends Tool
{
    protected string $name = 'blacklist_manage';

    protected string $description = 'Manage the outbound targeting blacklist. Blocked entries (emails, domains, companies, keywords) prevent outbound messages from being delivered. Operations: list, add, remove.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()
                ->description('list: get all blacklist entries | add: add a new entry | remove: remove an entry by id')
                ->enum(['list', 'add', 'remove'])
                ->required(),
            'type' => $schema->string()
                ->description('Required for add. Type of entry to block.')
                ->enum(['email', 'domain', 'company', 'keyword']),
            'value' => $schema->string()
                ->description('Required for add/remove. The value to block (e.g. "spam@example.com") or the entry UUID to remove.'),
            'reason' => $schema->string()
                ->description('Optional reason for add operations. Stored for audit purposes.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'operation' => 'required|string|in:list,add,remove',
            'type' => 'nullable|string|in:email,domain,company,keyword',
            'value' => 'nullable|string',
            'reason' => 'nullable|string',
        ]);

        try {
            return match ($validated['operation']) {
                'list' => $this->listEntries(),
                'add' => $this->addEntry($validated),
                'remove' => $this->removeEntry($validated),
            };
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    private function listEntries(): Response
    {
        $entries = Blacklist::orderBy('type')->orderBy('value')->get();

        return Response::text(json_encode([
            'count' => $entries->count(),
            'entries' => $entries->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'value' => $e->value,
                'reason' => $e->reason,
                'created_at' => $e->created_at?->toIso8601String(),
            ]),
        ]));
    }

    private function addEntry(array $data): Response
    {
        if (empty($data['type'])) {
            return Response::error('type is required for add operation.');
        }
        if (empty($data['value'])) {
            return Response::error('value is required for add operation.');
        }

        $entry = Blacklist::create([
            'type' => $data['type'],
            'value' => strtolower(trim($data['value'])),
            'reason' => $data['reason'] ?? null,
            'added_by' => auth()->id(),
        ]);

        return Response::text(json_encode([
            'success' => true,
            'id' => $entry->id,
            'type' => $entry->type,
            'value' => $entry->value,
        ]));
    }

    private function removeEntry(array $data): Response
    {
        if (empty($data['value'])) {
            return Response::error('value (entry UUID) is required for remove operation.');
        }

        $entry = Blacklist::find($data['value']);

        if (! $entry) {
            return Response::error('Blacklist entry not found.');
        }

        $entry->delete();

        return Response::text(json_encode([
            'success' => true,
            'removed_id' => $data['value'],
        ]));
    }
}
