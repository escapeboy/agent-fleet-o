<?php

namespace App\Domain\Skill\Services;

use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Crew\Models\CrewExecution;
use App\Infrastructure\AI\Models\LlmRequestLog;

class TrajectorySkillExtractor
{
    public function buildSummary(CrewExecution|AgentExecution $execution): string
    {
        if ($execution instanceof CrewExecution) {
            return $this->buildCrewSummary($execution);
        }

        return $this->buildAgentSummary($execution);
    }

    private function buildCrewSummary(CrewExecution $execution): string
    {
        $lines = [
            '# Crew Execution Trajectory',
            '',
            '## Goal',
            $execution->goal ?? '(no goal recorded)',
            '',
        ];

        if (! empty($execution->task_plan)) {
            $lines[] = '## Task Plan';
            foreach ((array) $execution->task_plan as $i => $task) {
                $lines[] = sprintf('- Task %d: %s', $i + 1, $task['description'] ?? $task['name'] ?? json_encode($task));
            }
            $lines[] = '';
        }

        $toolCalls = $this->extractToolCallsFromLogs($execution->experiment_id);
        if (! empty($toolCalls)) {
            $lines[] = '## Tool Usage';
            foreach (array_slice($toolCalls, 0, 20) as $call) {
                $lines[] = sprintf('- %s(%s)', $call['name'], substr(json_encode($call['input'] ?? []), 0, 100));
            }
            $lines[] = '';
        }

        $lines[] = '## Outcome';
        $finalOutput = $execution->final_output;
        if (is_array($finalOutput)) {
            $lines[] = $finalOutput['result'] ?? $finalOutput['summary'] ?? json_encode($finalOutput);
        } elseif (is_string($finalOutput)) {
            $lines[] = $finalOutput;
        }

        $lines[] = '';
        $lines[] = sprintf('Coordinator iterations: %d', $execution->coordinator_iterations ?? 0);

        $summary = implode("\n", $lines);

        return mb_substr($summary, 0, 8000);
    }

    private function buildAgentSummary(AgentExecution $execution): string
    {
        $lines = [
            '# Agent Execution Trajectory',
            '',
            '## Input',
            json_encode($execution->input ?? [], JSON_PRETTY_PRINT),
            '',
        ];

        if (! empty($execution->tools_used)) {
            $lines[] = '## Tools Used';
            foreach (array_slice((array) $execution->tools_used, 0, 20) as $tool) {
                $lines[] = '- '.(is_string($tool) ? $tool : json_encode($tool));
            }
            $lines[] = '';
        }

        $toolCalls = $this->extractToolCallsFromLogs($execution->experiment_id);
        if (! empty($toolCalls)) {
            $lines[] = '## Tool Calls';
            foreach (array_slice($toolCalls, 0, 20) as $call) {
                $lines[] = sprintf('- %s(%s)', $call['name'], substr(json_encode($call['input'] ?? []), 0, 100));
            }
            $lines[] = '';
        }

        $lines[] = '## Output';
        $output = $execution->output;
        if (is_array($output)) {
            $lines[] = json_encode($output, JSON_PRETTY_PRINT);
        } elseif (is_string($output)) {
            $lines[] = $output;
        }

        $lines[] = '';
        $lines[] = sprintf('Tool calls: %d | LLM steps: %d', $execution->tool_calls_count ?? 0, $execution->llm_steps_count ?? 0);

        $summary = implode("\n", $lines);

        return mb_substr($summary, 0, 8000);
    }

    private function extractToolCallsFromLogs(?string $experimentId): array
    {
        if (! $experimentId) {
            return [];
        }

        $logs = LlmRequestLog::withoutGlobalScopes()
            ->where('experiment_id', $experimentId)
            ->whereNotNull('response_body')
            ->latest()
            ->take(20)
            ->get();

        $toolCalls = [];
        foreach ($logs as $log) {
            $body = $log->response_body;
            if (! is_array($body)) {
                continue;
            }
            $content = $body['content'] ?? [];
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'tool_use') {
                    $toolCalls[] = [
                        'name' => $block['name'] ?? 'unknown',
                        'input' => $block['input'] ?? [],
                    ];
                }
            }
        }

        return $toolCalls;
    }
}
