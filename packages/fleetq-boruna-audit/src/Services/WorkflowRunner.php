<?php

namespace FleetQ\BorunaAudit\Services;

use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpStdioClient;
use FleetQ\BorunaAudit\DTOs\WorkflowRunResult;
use FleetQ\BorunaAudit\Enums\DecisionStatus;
use FleetQ\BorunaAudit\Exceptions\BorunaSidecarDown;
use FleetQ\BorunaAudit\Exceptions\BorunaWorkflowFailed;
use FleetQ\BorunaAudit\Models\AuditableDecision;
use Illuminate\Support\Str;

class WorkflowRunner
{
    public function __construct(
        private readonly McpStdioClient $client,
        private readonly BundleStorage $storage,
    ) {}

    public function run(
        string $workflowName,
        string $workflowVersion,
        array $inputs,
        string $tenantId,
        bool $shadowMode = false,
    ): WorkflowRunResult {
        $runId = (string) Str::uuid();

        $source = $this->loadWorkflowSource($workflowName, $workflowVersion);
        $policy = $this->loadWorkflowPolicy($workflowName, $workflowVersion);

        $tool = $this->resolveTool($tenantId);

        if ($tool === null) {
            throw new BorunaSidecarDown('No active Boruna tool found for this team.');
        }

        $decision = AuditableDecision::create([
            'team_id' => $tenantId,
            'workflow_name' => $workflowName,
            'workflow_version' => $workflowVersion,
            'run_id' => $runId,
            'status' => DecisionStatus::Running,
            'inputs' => $inputs,
            'shadow_mode' => $shadowMode,
        ]);

        try {
            $source = $this->interpolateInputs($source, $inputs);

            $arguments = [
                'source' => $source,
                'policy' => $policy,
                'limits' => ['max_wall_ms' => config('boruna_audit.workflow_timeout_ms', 5000)],
            ];

            $rawOutput = $this->client->callTool($tool, 'boruna_run', $arguments);
        } catch (\Throwable $e) {
            $decision->update(['status' => DecisionStatus::Failed]);

            throw new BorunaSidecarDown($e->getMessage());
        }

        $parsed = json_decode($rawOutput, true);
        if (! is_array($parsed)) {
            $parsed = ['output' => $rawOutput];
        }

        $evidence = $parsed['evidence'] ?? null;
        $output = array_filter($parsed, fn ($k) => $k !== 'evidence', ARRAY_FILTER_USE_KEY);

        if (! $output && isset($parsed['output'])) {
            $output = $parsed;
        }

        if (empty($output)) {
            $decision->update(['status' => DecisionStatus::Failed]);
            throw new BorunaWorkflowFailed($workflowName, 'Empty output from Boruna.');
        }

        $bundlePath = null;
        if ($evidence !== null) {
            $bundlePath = $this->storage->writeBundleFiles($tenantId, $runId, (array) $evidence);
        }

        $decision->update([
            'status' => DecisionStatus::Completed,
            'outputs' => $output,
            'evidence' => $evidence,
            'bundle_path' => $bundlePath,
        ]);

        return WorkflowRunResult::success($runId, $bundlePath ?? '', $output, $evidence);
    }

    private function loadWorkflowSource(string $name, string $version): string
    {
        $path = base_path("boruna_workflows/{$name}/{$version}/workflow.ax");

        if (! file_exists($path)) {
            throw new BorunaWorkflowFailed($name, "Workflow file not found at {$path}");
        }

        return file_get_contents($path);
    }

    private function loadWorkflowPolicy(string $name, string $version): array
    {
        $path = base_path("boruna_workflows/{$name}/{$version}/policy.json");

        if (! file_exists($path)) {
            return ['default_allow' => false];
        }

        $decoded = json_decode(file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['default_allow' => false];
    }

    private function resolveTool(string $tenantId): ?Tool
    {
        return Tool::where('team_id', $tenantId)
            ->where('type', 'mcp_stdio')
            ->where('status', 'active')
            ->where('subkind', 'boruna')
            ->first();
    }

    private function interpolateInputs(string $source, array $inputs): string
    {
        foreach ($inputs as $key => $value) {
            $encoded = is_array($value) ? json_encode($value) : (string) $value;
            // Escape any { or } in the value to prevent injection into the .ax script body.
            $safe = str_replace(['{', '}'], ['\\{', '\\}'], $encoded);
            $source = str_replace("{{{$key}}}", $safe, $source);
        }

        return $source;
    }
}
