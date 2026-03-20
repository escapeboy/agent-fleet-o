<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Adapters\SupabaseVectorAdapter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SupabaseProvisionMemoryTool extends Tool
{
    protected string $name = 'supabase_provision_vector_memory';

    protected string $description = 'Get the setup SQL to run once in a Supabase project to enable FleetQ agent memory storage (pgvector table + similarity search function). Returns ready-to-paste SQL for the Supabase SQL editor.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'embedding_dimension' => $schema->integer()
                ->description('Embedding dimension of your model. OpenAI text-embedding-3-small = 1536, Anthropic/Voyage = 1024, Google text-embedding-004 = 768. Default: 1536.')
                ->minimum(64)
                ->maximum(4096),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'embedding_dimension' => 'nullable|integer|min:64|max:4096',
        ]);

        $dimension = $validated['embedding_dimension'] ?? 1536;

        $sql = SupabaseVectorAdapter::getSetupSql($dimension);

        return Response::text(json_encode([
            'embedding_dimension' => $dimension,
            'setup_sql' => $sql,
            'instructions' => [
                '1. Go to your Supabase dashboard → SQL Editor → New query',
                '2. Paste the setup_sql below and click Run',
                '3. In FleetQ, connect your Supabase project via Integrations',
                '4. Agents can now store and search memories in your Supabase project',
            ],
            'rest_endpoint' => 'POST {project_url}/rest/v1/rpc/fleetq_match_memories',
            'note' => 'Run this once per Supabase project. Re-running is safe (uses IF NOT EXISTS / OR REPLACE).',
        ]));
    }
}
