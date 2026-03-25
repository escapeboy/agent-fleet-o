<?php

namespace App\Domain\Workflow\Executors;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Contracts\NodeExecutorInterface;
use App\Domain\Workflow\Models\WorkflowNode;

/**
 * Renders a text template with {{variable}} placeholders replaced from
 * predecessor step outputs. Zero LLM cost.
 *
 * Config shape:
 * {
 *   "template": "Dear {{contact_name}},\n\nYour summary:\n{{summariser_node_id.text}}"
 * }
 */
class TemplateTransformNodeExecutor implements NodeExecutorInterface
{
    use InterpolatesTemplates;

    public function execute(WorkflowNode $node, PlaybookStep $step, Experiment $experiment): array
    {
        $config = $this->parseConfig($node->config);
        $template = $config['template'] ?? '';

        if ($template === '') {
            return ['rendered' => ''];
        }

        $context = $this->buildStepContext($step, $experiment);

        // Also expose a flat variables map if provided in config
        foreach ($config['variables'] ?? [] as $key => $value) {
            if (! isset($context[$key])) {
                $context[$key] = $value;
            }
        }

        $rendered = $this->interpolate($template, $context);

        return ['rendered' => $rendered];
    }
}
