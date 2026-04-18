<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Models\SshHostFingerprint;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ToolSshFingerprintsTool extends Tool
{
    protected string $name = 'tool_ssh_fingerprints';

    protected string $description = 'Manage trusted SSH host fingerprints (TOFU store). '
        .'Actions: list — show all trusted hosts; delete — remove a fingerprint so the next connection re-verifies via TOFU (use after legitimate host key rotation).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action to perform: list or delete')
                ->enum(['list', 'delete'])
                ->required(),
            'fingerprint_id' => $schema->string()
                ->description('Fingerprint ID to delete (required for delete action)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => 'required|string|in:list,delete',
            'fingerprint_id' => 'nullable|string',
        ]);

        return match ($validated['action']) {
            'list' => $this->list(),
            'delete' => $this->delete($validated['fingerprint_id'] ?? null),
            default => Response::error('Unknown action'),
        };
    }

    private function list(): Response
    {
        $fingerprints = SshHostFingerprint::orderBy('host')
            ->orderBy('port')
            ->get(['id', 'host', 'port', 'fingerprint_sha256', 'verified_at', 'created_at']);

        if ($fingerprints->isEmpty()) {
            return Response::text('No trusted SSH hosts yet. Fingerprints are stored automatically on first connection (TOFU).');
        }

        $rows = $fingerprints->map(fn ($f) => sprintf(
            '%-30s  port %-5d  sha256:%s  verified:%s',
            $f->host,
            $f->port,
            substr($f->fingerprint_sha256, 0, 16).'...',
            $f->verified_at?->toDateTimeString() ?? 'never',
        ))->join("\n");

        return Response::text("Trusted SSH hosts ({$fingerprints->count()}):\n\n{$rows}");
    }

    private function delete(?string $fingerprintId): Response
    {
        if (! $fingerprintId) {
            return Response::error('fingerprint_id is required for delete action');
        }

        $fingerprint = SshHostFingerprint::find($fingerprintId);

        if (! $fingerprint) {
            return Response::error("Fingerprint {$fingerprintId} not found.");
        }

        $label = "{$fingerprint->host}:{$fingerprint->port}";
        $fingerprint->delete();

        return Response::text("Fingerprint for {$label} removed. Next SSH connection will re-verify via TOFU.");
    }
}
