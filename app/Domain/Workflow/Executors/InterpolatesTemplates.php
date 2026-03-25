<?php

namespace App\Domain\Workflow\Executors;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;

trait InterpolatesTemplates
{
    /**
     * Render a template string with {{variable}} placeholders replaced from context.
     *
     * Supports dot-notation: {{node_id.field}} or {{field}}.
     */
    protected function interpolate(string $template, array $context): string
    {
        return preg_replace_callback('/\{\{([^}]+)\}\}/', function (array $matches) use ($context): string {
            $key = trim($matches[1]);
            $value = data_get($context, $key);

            if ($value === null) {
                return $matches[0]; // leave unreplaced
            }

            return is_string($value) ? $value : json_encode($value);
        }, $template);
    }

    /**
     * Build a context array from all completed predecessor steps, plus experiment data.
     *
     * @return array<string, mixed>
     */
    protected function buildStepContext(PlaybookStep $step, Experiment $experiment): array
    {
        $context = [
            'experiment' => [
                'id' => $experiment->id,
                'title' => $experiment->title,
                'thesis' => $experiment->thesis,
            ],
        ];

        $completedSteps = PlaybookStep::where('experiment_id', $experiment->id)
            ->where('status', 'completed')
            ->where('id', '!=', $step->id)
            ->get();

        foreach ($completedSteps as $completedStep) {
            if ($completedStep->workflow_node_id && is_array($completedStep->output)) {
                $context[$completedStep->workflow_node_id] = $completedStep->output;
            }
        }

        return $context;
    }

    /**
     * Parse the node config from WorkflowNode, supporting both string JSON and array.
     *
     * @return array<string, mixed>
     */
    protected function parseConfig(mixed $config): array
    {
        if (is_string($config)) {
            return json_decode($config, true) ?? [];
        }

        return is_array($config) ? $config : [];
    }
}
