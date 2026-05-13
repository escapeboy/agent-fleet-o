<?php

namespace App\Mcp\Tools\Credential;

use App\Domain\Credential\Models\Credential;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[AssistantTool('write')]
class CredentialSetDomainAllowlistTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'credential_set_domain_allowlist';

    protected string $description = 'Set the domain allowlist for a credential. Only these domains will be permitted to use this credential. Pass an empty array to allow all domains. Supports wildcards like *.example.com.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'credential_id' => $schema->string()
                ->description('The credential UUID')
                ->required(),
            'domains' => $schema->array()
                ->items($schema->string())
                ->description('List of allowed domains. Empty array removes restrictions.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'credential_id' => 'required|string',
            'domains' => 'required|array',
            'domains.*' => 'string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $credential = Credential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['credential_id']);

        if (! $credential) {
            return $this->notFound('Credential', $validated['credential_id']);
        }

        $domains = array_values(array_filter(array_map('trim', $validated['domains'])));

        $credential->update([
            'allowed_domains' => empty($domains) ? null : $domains,
        ]);

        return Response::text(json_encode([
            'credential_id' => $credential->id,
            'allowed_domains' => $credential->fresh()->allowed_domains,
            'message' => empty($domains)
                ? 'Domain restrictions removed — all domains are now allowed.'
                : 'Domain allowlist updated.',
        ], JSON_PRETTY_PRINT));
    }
}
