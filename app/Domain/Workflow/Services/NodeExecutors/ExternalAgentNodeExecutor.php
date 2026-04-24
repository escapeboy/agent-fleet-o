<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Services\NodeExecutors;

use App\Domain\AgentChatProtocol\Services\WorkflowExternalAgentHandler;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Contracts\NodeExecutorInterface;
use App\Domain\Workflow\Models\WorkflowNode;

class ExternalAgentNodeExecutor implements NodeExecutorInterface
{
    public function __construct(private readonly WorkflowExternalAgentHandler $handler) {}

    public function execute(WorkflowNode $node, PlaybookStep $step, Experiment $experiment): array
    {
        $input = (array) ($step->input_data ?? []);

        $result = $this->handler->handle($step, $node, $input);

        $outputMapping = (array) ($node->config['output_mapping'] ?? []);
        if ($outputMapping !== []) {
            $mapped = [];
            foreach ($outputMapping as $nodeOutputKey => $remoteJsonPath) {
                $mapped[$nodeOutputKey] = data_get($result['remote_response'] ?? $result, (string) $remoteJsonPath);
            }

            return $mapped;
        }

        return [
            'result' => $result['remote_response']['content'] ?? $result['output'] ?? null,
            'session_id' => $result['session_id'] ?? null,
            'session_token' => $result['session_token'] ?? null,
            'raw' => $result,
        ];
    }
}
