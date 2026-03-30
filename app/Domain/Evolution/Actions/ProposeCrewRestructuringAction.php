<?php

namespace App\Domain\Evolution\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Models\Crew;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Database\Eloquent\Collection;

class ProposeCrewRestructuringAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    public function execute(Crew $crew, string $userId): ApprovalRequest
    {
        $executions = $crew->executions()
            ->whereIn('status', [
                CrewExecutionStatus::Completed->value,
                CrewExecutionStatus::Failed->value,
            ])
            ->latest()
            ->limit(10)
            ->get();

        $members = $crew->members()->with('agent')->get();
        $metrics = $this->computeMetrics($executions);
        $context = $this->buildAnalysisContext($crew, $members, $metrics);

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            systemPrompt: $this->buildSystemPrompt(),
            userPrompt: $context,
            teamId: $crew->team_id,
            maxTokens: 2048,
            temperature: 0.3,
            purpose: 'crew.propose_restructuring',
        ));

        $parsed = $this->parseResponse($response->content);

        return ApprovalRequest::create([
            'team_id' => $crew->team_id,
            'status' => ApprovalStatus::Pending,
            'context' => [
                'type' => 'crew_restructuring',
                'crew_id' => $crew->id,
                'crew_name' => $crew->name,
                'analysis' => $parsed['analysis'],
                'proposed_changes' => $parsed['proposed_changes'],
                'confidence' => $parsed['confidence'],
                'summary' => $parsed['summary'],
                'metrics' => $metrics,
                'requested_by' => $userId,
            ],
            'expires_at' => now()->addHours(48),
        ]);
    }

    private function computeMetrics(Collection $executions): array
    {
        if ($executions->isEmpty()) {
            return [
                'total' => 0,
                'completed' => 0,
                'failed' => 0,
                'success_rate' => null,
                'avg_duration_ms' => null,
                'failure_reasons' => [],
            ];
        }

        $total = $executions->count();
        $completed = $executions->where('status', CrewExecutionStatus::Completed)->count();
        $failed = $executions->where('status', CrewExecutionStatus::Failed)->count();

        $durations = $executions->whereNotNull('duration_ms')->pluck('duration_ms');
        $avgDuration = $durations->isNotEmpty() ? (int) $durations->average() : null;

        $failureReasons = $executions
            ->where('status', CrewExecutionStatus::Failed)
            ->whereNotNull('error_message')
            ->pluck('error_message')
            ->unique()
            ->values()
            ->toArray();

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round($completed / $total, 2) : null,
            'avg_duration_ms' => $avgDuration,
            'failure_reasons' => array_slice($failureReasons, 0, 5),
        ];
    }

    private function buildAnalysisContext(
        Crew $crew,
        Collection $members,
        array $metrics,
    ): string {
        $parts = [
            "Crew: {$crew->name}",
            "Process Type: {$crew->process_type->value}",
            "Member Count: {$members->count()}",
        ];

        if ($crew->description) {
            $parts[] = "Description: {$crew->description}";
        }

        $memberList = $members->map(function ($m) {
            $agentName = $m->agent?->name ?? 'unknown';

            return "  - Role: {$m->role->value}, Agent: {$agentName}";
        });
        if ($memberList->isNotEmpty()) {
            $parts[] = "Members:\n".$memberList->implode("\n");
        }

        $parts[] = 'Execution Metrics:';
        $parts[] = "  Total executions (last 10): {$metrics['total']}";
        $parts[] = "  Completed: {$metrics['completed']}, Failed: {$metrics['failed']}";

        if ($metrics['success_rate'] !== null) {
            $successPct = $metrics['success_rate'] * 100;
            $parts[] = "  Success rate: {$successPct}%";
        }

        if ($metrics['avg_duration_ms'] !== null) {
            $avgSeconds = round($metrics['avg_duration_ms'] / 1000, 1);
            $parts[] = "  Avg duration: {$avgSeconds}s";
        }

        if (! empty($metrics['failure_reasons'])) {
            $parts[] = 'Common failure reasons:';
            foreach ($metrics['failure_reasons'] as $reason) {
                $truncated = mb_substr($reason, 0, 200);
                $parts[] = "  - {$truncated}";
            }
        }

        return implode("\n", $parts);
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an AI agent team analyst. Analyze the crew's structure and execution history to propose structural improvements.

Respond ONLY with valid JSON in this exact format:
{
    "analysis": {
        "bottleneck_roles": ["role descriptions that slow the crew"],
        "redundant_roles": ["roles that overlap or are unnecessary"],
        "missing_roles": ["roles that would improve outcomes"],
        "coordination_failures": ["patterns causing coordination issues"]
    },
    "proposed_changes": [
        {"action": "add_role", "role": "role_name", "rationale": "why this helps"},
        {"action": "remove_member", "agent_id": "uuid or null", "rationale": "why"},
        {"action": "change_process_type", "new_type": "sequential|hierarchical|consensual", "rationale": "why"}
    ],
    "confidence": 0.75,
    "summary": "One paragraph summarising the key restructuring recommendation and expected impact."
}

Rules:
- Only propose changes with clear rationale based on the metrics
- proposed_changes may be an empty array if the structure is already optimal
- confidence should be 0.0-1.0 based on data quality and certainty
- Keep summary concise (2-4 sentences)
PROMPT;
    }

    private function parseResponse(string $content): array
    {
        $defaults = [
            'analysis' => [
                'bottleneck_roles' => [],
                'redundant_roles' => [],
                'missing_roles' => [],
                'coordination_failures' => [],
            ],
            'proposed_changes' => [],
            'confidence' => 0.5,
            'summary' => 'Unable to parse restructuring analysis.',
        ];

        $json = $content;
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $content, $matches)) {
            $json = $matches[1];
        }

        $parsed = json_decode(trim($json), true);
        if (! is_array($parsed)) {
            return array_merge($defaults, ['summary' => $content]);
        }

        return [
            'analysis' => $parsed['analysis'] ?? $defaults['analysis'],
            'proposed_changes' => $parsed['proposed_changes'] ?? [],
            'confidence' => min(1.0, max(0.0, (float) ($parsed['confidence'] ?? 0.5))),
            'summary' => $parsed['summary'] ?? $defaults['summary'],
        ];
    }
}
