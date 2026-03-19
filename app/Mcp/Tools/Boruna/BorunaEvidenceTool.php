<?php

namespace App\Mcp\Tools\Boruna;

use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpStdioClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Retrieve the cryptographic evidence bundle for a past Boruna execution.
 *
 * Boruna produces a deterministic evidence bundle containing the script hash,
 * input hash, output hash, capability trace, and a signature that proves the
 * execution occurred in the Boruna VM without tampering.
 */
#[IsReadOnly]
class BorunaEvidenceTool extends McpTool
{
    protected string $name = 'boruna_evidence_get';

    protected string $description = 'Retrieve the cryptographic evidence bundle for a Boruna execution. The bundle contains script hash, input hash, output hash, capability trace, and a tamper-proof signature proving the execution occurred in the Boruna VM.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()
                ->description('UUID of the SkillExecution record from a boruna_script skill run')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'execution_id' => 'required|uuid',
        ]);

        $teamId = auth()->user()->current_team_id;

        $execution = SkillExecution::with('skill')
            ->where('id', $validated['execution_id'])
            ->where('team_id', $teamId)
            ->first();

        if (! $execution) {
            return Response::error('SkillExecution not found.');
        }

        if ($execution->skill?->type !== 'boruna_script') {
            return Response::error('This execution is not from a boruna_script skill.');
        }

        // The evidence bundle is stored in the execution output under the 'evidence' key
        // if the Boruna MCP server returned it. For executions that only returned plain
        // output text, we surface what is available.
        $output = is_array($execution->output) ? $execution->output : [];
        $evidence = $output['evidence'] ?? null;

        if (! $evidence) {
            // Try fetching fresh evidence from the Boruna tool for this execution
            $tool = $this->resolveTool($teamId);

            if ($tool) {
                try {
                    $raw = app(McpStdioClient::class)->callTool($tool, 'boruna_evidence', [
                        'execution_id' => $execution->id,
                    ]);
                    $evidence = json_decode($raw, true) ?? ['raw' => $raw];
                } catch (\Throwable) {
                    // Evidence fetch failed — surface what we have
                }
            }
        }

        return Response::text(json_encode([
            'execution_id' => $execution->id,
            'skill_id'     => $execution->skill_id,
            'skill_name'   => $execution->skill?->name,
            'status'       => $execution->status,
            'duration_ms'  => $execution->duration_ms,
            'created_at'   => $execution->created_at,
            'evidence'     => $evidence ?? '(evidence not available — run a boruna_script skill to generate one)',
        ]));
    }

    private function resolveTool(string $teamId): ?Tool
    {
        return Tool::where('team_id', $teamId)
            ->where('type', 'mcp_stdio')
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereRaw("transport_config->>'command' ILIKE '%boruna%'")
                    ->orWhereRaw("transport_config->>'command' ILIKE '%boruna-mcp%'");
            })
            ->first();
    }
}
