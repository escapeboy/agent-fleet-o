<?php

namespace App\Mcp\Tools\AuditConsole;

use App\Mcp\Concerns\HasStructuredErrors;
use FleetQ\BorunaAudit\Models\AuditableDecision;
use FleetQ\BorunaAudit\Services\BundleVerifier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class AuditConsoleVerifyBundleTool extends McpTool
{
    use HasStructuredErrors;

    protected string $name = 'audit_console_verify_bundle';

    protected string $description = 'Verify the cryptographic integrity of a Boruna evidence bundle. Returns pass/fail with error details. Updates the decision status to tampered if verification fails.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'decision_id' => $schema->string()
                ->description('UUID of the auditable decision to verify')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        $decision = AuditableDecision::where('id', $request->get('decision_id'))
            ->where('team_id', $teamId)
            ->first();

        if ($decision === null) {
            return $this->notFoundError('decision', $request->get('decision_id'));
        }

        $verifier = app(BundleVerifier::class);
        $result = $verifier->verify($decision, (string) $teamId);

        return Response::text(json_encode([
            'passed' => $result->passed,
            'checked_at' => $result->checkedAt->format(\DateTimeInterface::ATOM),
            'error_message' => $result->errorMessage,
            'bundle_path' => $result->bundlePath,
        ]));
    }
}
