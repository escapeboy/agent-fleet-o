<?php

namespace App\Mcp\Tools\Credential;

use App\Domain\Credential\Models\Credential;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class CredentialListTool extends Tool
{
    protected string $name = 'credential_list';

    protected string $description = 'List credentials with optional status filter. Returns id, name, type, status, and expiry. Never includes secret data.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: active, disabled')
                ->enum(['active', 'disabled']),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = Credential::query()->orderBy('name');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $limit = min((int) ($request->get('limit', 10)), 100);

        $credentials = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $credentials->count(),
            'credentials' => $credentials->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->credential_type->value,
                'status' => $c->status->value,
                'expires_at' => $c->expires_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
