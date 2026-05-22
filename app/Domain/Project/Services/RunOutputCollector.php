<?php

namespace App\Domain\Project\Services;

use App\Domain\Experiment\Models\PlaybookStep;

class RunOutputCollector
{
    /**
     * Collect completed playbook step outputs into a markdown summary.
     */
    public function collect(string $experimentId): string
    {
        $steps = PlaybookStep::where('experiment_id', $experimentId)
            ->where('status', 'completed')
            ->orderBy('order')
            ->get();

        if ($steps->isEmpty()) {
            return 'Workflow completed with no output.';
        }

        $parts = [];
        foreach ($steps as $step) {
            $output = $step->output;
            if (! $output) {
                continue;
            }

            $label = $step->label ?? $step->skill_name ?? "Step {$step->order}";

            if (is_array($output)) {
                $text = $output['result'] ?? $output['text'] ?? $output['content'] ?? json_encode($output, JSON_PRETTY_PRINT);
            } else {
                $text = (string) $output;
            }

            $parts[] = "## {$label}\n\n{$text}";
        }

        return implode("\n\n---\n\n", $parts) ?: 'Workflow completed with no output.';
    }
}
