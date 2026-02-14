<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\ExperimentStateTransition;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Infrastructure\AI\Models\LlmRequestLog;
use Livewire\Component;

class ExecutionLogPanel extends Component
{
    public string $experimentId;

    public ?string $expandedEventId = null;

    public function toggleEvent(string $eventId): void
    {
        $this->expandedEventId = $this->expandedEventId === $eventId ? null : $eventId;
    }

    public function render()
    {
        $events = collect();

        // State transitions
        $transitions = ExperimentStateTransition::withoutGlobalScopes()
            ->where('experiment_id', $this->experimentId)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($t) => [
                'id' => 'transition-'.$t->id,
                'type' => 'transition',
                'time' => $t->created_at,
                'summary' => "{$t->from_state} -> {$t->to_state}",
                'detail' => $t->reason,
                'metadata' => $t->metadata,
                'icon' => 'arrow-right',
                'color' => str_contains($t->to_state, 'failed') ? 'red' : (str_contains($t->to_state, 'completed') ? 'green' : 'blue'),
            ]);
        $events = $events->merge($transitions);

        // Experiment stages
        $stages = ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $this->experimentId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (ExperimentStage $s) => [
                'id' => 'stage-'.$s->id,
                'type' => 'stage',
                'time' => $s->started_at ?? $s->created_at,
                'summary' => "Stage: {$s->stage->value} ({$s->status->value})",
                'detail' => $s->duration_ms ? round($s->duration_ms / 1000, 1).'s' : null,
                'metadata' => null,
                'icon' => 'cog',
                'color' => match ($s->status->value) {
                    'completed' => 'green',
                    'failed' => 'red',
                    'running' => 'blue',
                    default => 'gray',
                },
            ]);
        $events = $events->merge($stages);

        // Playbook steps
        $steps = PlaybookStep::where('experiment_id', $this->experimentId)
            ->whereNotNull('started_at')
            ->orderBy('started_at')
            ->get()
            ->map(fn (PlaybookStep $s) => [
                'id' => 'step-'.$s->id,
                'type' => 'step',
                'time' => $s->started_at,
                'summary' => "Step #{$s->order}: ".($s->agent?->name ?? 'Unknown agent')." ({$s->status})",
                'detail' => $s->error_message ?? ($s->duration_ms ? round($s->duration_ms / 1000, 1).'s, '.($s->cost_credits ?? 0).' credits' : null),
                'metadata' => null,
                'icon' => 'play',
                'color' => match ($s->status) {
                    'completed' => 'green',
                    'failed' => 'red',
                    'running' => 'blue',
                    default => 'gray',
                },
            ]);
        $events = $events->merge($steps);

        // LLM calls
        $llmLogs = LlmRequestLog::withoutGlobalScopes()
            ->where('experiment_id', $this->experimentId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (LlmRequestLog $l) => [
                'id' => 'llm-'.$l->id,
                'type' => 'llm_call',
                'time' => $l->created_at,
                'summary' => "LLM: {$l->provider}/{$l->model} ({$l->status})",
                'detail' => collect([
                    $l->input_tokens ? "{$l->input_tokens} in" : null,
                    $l->output_tokens ? "{$l->output_tokens} out" : null,
                    $l->cost_credits ? "{$l->cost_credits} credits" : null,
                    $l->latency_ms ? round($l->latency_ms / 1000, 1).'s' : null,
                ])->filter()->implode(', '),
                'metadata' => $l->error ? ['error' => $l->error] : null,
                'icon' => 'chip',
                'color' => match ($l->status) {
                    'completed', 'success' => 'green',
                    'failed', 'error' => 'red',
                    default => 'gray',
                },
            ]);
        $events = $events->merge($llmLogs);

        return view('livewire.experiments.execution-log-panel', [
            'events' => $events->sortBy('time')->values(),
        ]);
    }
}
