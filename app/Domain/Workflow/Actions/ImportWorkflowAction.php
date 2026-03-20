<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class ImportWorkflowAction
{
    /**
     * Import a workflow from a JSON/YAML snapshot created by ExportWorkflowAction.
     *
     * Supports both v1 (flat) and v2 (envelope) formats.
     * Auto-detects YAML if $content string is provided instead of $data array.
     *
     * @return array{workflow: Workflow, unresolved_references: array, checksum_valid: bool|null}
     *
     * @throws \InvalidArgumentException
     */
    public function execute(
        array|string $data,
        string $teamId,
        string $userId,
        ?string $nameOverride = null,
    ): array {
        // Parse string content (JSON or YAML)
        if (is_string($data)) {
            $data = $this->parseContent($data);
        }

        // Detect v2 envelope vs v1 flat format
        $isV2 = isset($data['format_version']);
        $checksumValid = null;

        if ($isV2) {
            $checksumValid = $this->verifyChecksum($data);
            // The actual data is in the same structure, just with extra envelope fields
        } else {
            // v1 format — validate legacy structure
            $this->validateV1($data);
        }

        $nodes = $data['nodes'] ?? [];
        if (count($nodes) > 500) {
            throw new \InvalidArgumentException('Workflow exceeds the maximum of 500 nodes.');
        }

        $this->validateCommon($data);

        // Fuzzy reference resolution
        $resolvedRefs = $this->resolveReferences($nodes, $teamId);

        $workflow = DB::transaction(function () use ($data, $teamId, $userId, $nameOverride, $resolvedRefs) {
            $wf = $data['workflow'];
            $name = $nameOverride ?? ($wf['name'] ?? 'Imported Workflow');

            $workflow = Workflow::create([
                'team_id' => $teamId,
                'user_id' => $userId,
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::random(6),
                'description' => $wf['description'] ?? null,
                'status' => WorkflowStatus::Draft,
                'version' => 1,
                'max_loop_iterations' => $wf['max_loop_iterations'] ?? 5,
                'estimated_cost_credits' => $wf['estimated_cost_credits'] ?? null,
                'settings' => $wf['settings'] ?? [],
            ]);

            $nodeIdMap = [];

            foreach ($data['nodes'] ?? [] as $nodeData) {
                $type = WorkflowNodeType::tryFrom($nodeData['type'] ?? '') ?? WorkflowNodeType::Agent;

                $agentId = $resolvedRefs['agents'][$nodeData['agent_id'] ?? ''] ?? null;
                $skillId = $resolvedRefs['skills'][$nodeData['skill_id'] ?? ''] ?? null;
                $crewId = $resolvedRefs['crews'][$nodeData['crew_id'] ?? ''] ?? null;

                $newNode = WorkflowNode::create([
                    'workflow_id' => $workflow->id,
                    'agent_id' => $agentId,
                    'skill_id' => $skillId,
                    'crew_id' => $crewId,
                    'type' => $type,
                    'label' => $nodeData['label'] ?? 'Node',
                    'position_x' => $nodeData['position_x'] ?? 0,
                    'position_y' => $nodeData['position_y'] ?? 0,
                    'config' => $nodeData['config'] ?? [],
                    'order' => $nodeData['order'] ?? 0,
                ]);
                $nodeIdMap[$nodeData['id']] = $newNode->id;
            }

            foreach ($data['edges'] ?? [] as $edgeData) {
                $sourceId = $nodeIdMap[$edgeData['source_node_id']] ?? null;
                $targetId = $nodeIdMap[$edgeData['target_node_id']] ?? null;

                if ($sourceId && $targetId) {
                    WorkflowEdge::create([
                        'workflow_id' => $workflow->id,
                        'source_node_id' => $sourceId,
                        'target_node_id' => $targetId,
                        'condition' => $edgeData['condition'] ?? null,
                        'label' => $edgeData['label'] ?? null,
                        'is_default' => $edgeData['is_default'] ?? false,
                        'sort_order' => $edgeData['sort_order'] ?? 0,
                        'case_value' => $edgeData['case_value'] ?? null,
                        'source_channel' => $edgeData['source_channel'] ?? null,
                        'target_channel' => $edgeData['target_channel'] ?? null,
                    ]);
                }
            }

            return $workflow;
        });

        return [
            'workflow' => $workflow,
            'unresolved_references' => $resolvedRefs['unresolved'],
            'checksum_valid' => $checksumValid,
        ];
    }

    /**
     * Parse JSON or YAML string content into an array.
     */
    private function parseContent(string $content): array
    {
        // Try JSON first
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Try YAML
        try {
            $parsed = Yaml::parse($content, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            if (is_array($parsed)) {
                return $parsed;
            }
        } catch (\Throwable $e) {
            // Fall through to error
        }

        throw new \InvalidArgumentException('Content is neither valid JSON nor valid YAML.');
    }

    /**
     * Verify the SHA-256 checksum of a v2 envelope.
     */
    private function verifyChecksum(array $data): bool
    {
        $expectedChecksum = $data['checksum'] ?? null;
        if (! $expectedChecksum) {
            return false;
        }

        $workflowData = $data['workflow'] ?? [];
        $nodesData = $data['nodes'] ?? [];
        $edgesData = $data['edges'] ?? [];

        $actualChecksum = hash('sha256', json_encode([$workflowData, $nodesData, $edgesData]));

        if ($actualChecksum !== $expectedChecksum) {
            Log::warning('Workflow import checksum mismatch', [
                'expected' => $expectedChecksum,
                'actual' => $actualChecksum,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Resolve agents, skills, and crews by name within the team scope.
     */
    private function resolveReferences(array $nodes, string $teamId): array
    {
        $resolved = [
            'agents' => [],
            'skills' => [],
            'crews' => [],
            'unresolved' => [],
        ];

        $agentNames = [];
        $skillNames = [];
        $crewNames = [];

        foreach ($nodes as $node) {
            if (! empty($node['agent_hint']['name'])) {
                $agentNames[$node['agent_id'] ?? ''] = $node['agent_hint']['name'];
            }
            if (! empty($node['skill_hint']['name'])) {
                $skillNames[$node['skill_id'] ?? ''] = $node['skill_hint']['name'];
            }
            if (! empty($node['crew_hint']['name'])) {
                $crewNames[$node['crew_id'] ?? ''] = $node['crew_hint']['name'];
            }
        }

        // Resolve agents by name
        foreach ($agentNames as $originalId => $name) {
            $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->where('name', $name)->first();
            if ($agent) {
                $resolved['agents'][$originalId] = $agent->id;
            } else {
                $resolved['unresolved'][] = ['type' => 'agent', 'name' => $name, 'original_id' => $originalId];
            }
        }

        // Resolve skills by name
        foreach ($skillNames as $originalId => $name) {
            $skill = Skill::withoutGlobalScopes()->where('team_id', $teamId)->where('name', $name)->first();
            if ($skill) {
                $resolved['skills'][$originalId] = $skill->id;
            } else {
                $resolved['unresolved'][] = ['type' => 'skill', 'name' => $name, 'original_id' => $originalId];
            }
        }

        // Resolve crews by name
        foreach ($crewNames as $originalId => $name) {
            $crew = Crew::withoutGlobalScopes()->where('team_id', $teamId)->where('name', $name)->first();
            if ($crew) {
                $resolved['crews'][$originalId] = $crew->id;
            } else {
                $resolved['unresolved'][] = ['type' => 'crew', 'name' => $name, 'original_id' => $originalId];
            }
        }

        return $resolved;
    }

    private function validateV1(array $data): void
    {
        $validator = Validator::make($data, [
            'version' => 'required|string',
            'workflow' => 'required|array',
            'nodes' => 'present|array',
            'edges' => 'present|array',
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException(
                'Invalid workflow export format: '.implode(', ', $validator->errors()->all()),
            );
        }
    }

    private function validateCommon(array $data): void
    {
        $validator = Validator::make($data, [
            'workflow' => 'required|array',
            'nodes' => 'present|array',
            'edges' => 'present|array',
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException(
                'Invalid workflow export format: '.implode(', ', $validator->errors()->all()),
            );
        }
    }
}
