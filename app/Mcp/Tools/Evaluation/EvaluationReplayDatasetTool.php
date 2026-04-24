<?php

namespace App\Mcp\Tools\Evaluation;

use App\Domain\Evaluation\Actions\ReplayEvaluationDatasetAction;
use App\Domain\Evaluation\Jobs\ReplayEvaluationDatasetJob;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class EvaluationReplayDatasetTool extends Tool
{
    protected string $name = 'evaluation_replay_dataset';

    protected string $description = 'Replay a curated EvaluationDataset against a new provider/model/prompt. For each case, runs the input through the target model, then LLM-judges the output vs expected_output across one or more criteria (correctness, relevance, faithfulness, completeness). Writes per-case EvaluationRunResult rows + aggregate scores. Use `evaluation_run` with action=get afterwards to read results.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'dataset_id' => $schema->string()->description('ID of the EvaluationDataset to replay')->required(),
            'target_provider' => $schema->string()->description('Provider to test, e.g. anthropic, openai, google')->required(),
            'target_model' => $schema->string()->description('Model name to test, e.g. claude-haiku-4-5-20251001')->required(),
            'system_prompt' => $schema->string()->description('Optional system prompt override. If omitted, uses a generic "helpful assistant" baseline.'),
            'criteria' => $schema->array()
                ->description('Criteria list: correctness, relevance, faithfulness, completeness. Default: [correctness, relevance]')
                ->items($schema->string()),
            'judge_model' => $schema->string()->description('Judge model override (default: anthropic/claude-sonnet-4-5)'),
            'max_cases' => $schema->integer()->description('Max cases to replay (default 100, hard cap 500)'),
            'sync' => $schema->boolean()->description('Run inline (blocks) instead of queueing. Use only for <=10 cases. Default false.')->default(false),
        ];
    }

    public function handle(Request $request, ReplayEvaluationDatasetAction $action): Response
    {
        $user = auth()->user();
        if (! $user || ! $user->current_team_id) {
            return Response::error('Authentication or team context missing');
        }

        $datasetId = (string) $request->get('dataset_id', '');
        if ($datasetId === '') {
            return Response::error('dataset_id is required');
        }

        $dataset = EvaluationDataset::find($datasetId);
        if ($dataset === null) {
            return Response::error("Dataset {$datasetId} not found or not in your team");
        }

        $provider = trim((string) $request->get('target_provider', ''));
        $model = trim((string) $request->get('target_model', ''));
        if ($provider === '' || $model === '') {
            return Response::error('target_provider and target_model are required');
        }

        $criteriaInput = $request->get('criteria');
        $criteria = is_array($criteriaInput) && $criteriaInput !== []
            ? array_values(array_filter(array_map('strval', $criteriaInput)))
            : ['correctness', 'relevance'];

        $systemPrompt = $request->get('system_prompt');
        $judgeModel = $request->get('judge_model');
        $maxCases = max(1, min(500, (int) $request->get('max_cases', 100)));
        $sync = (bool) $request->get('sync', false);

        if ($sync) {
            try {
                $run = $action->execute(
                    teamId: $user->current_team_id,
                    datasetId: $datasetId,
                    targetProvider: $provider,
                    targetModel: $model,
                    systemPrompt: is_string($systemPrompt) && $systemPrompt !== '' ? $systemPrompt : null,
                    criteria: $criteria,
                    judgeModel: is_string($judgeModel) && $judgeModel !== '' ? $judgeModel : null,
                    maxCases: $maxCases,
                );
            } catch (\Throwable $e) {
                return Response::error('Replay failed: '.$e->getMessage());
            }

            return Response::text(json_encode([
                'run_id' => $run->id,
                'status' => $run->status->value,
                'aggregate_scores' => $run->aggregate_scores,
                'summary' => $run->summary,
                'regression_detected' => ($run->summary['overall_avg_score'] ?? 0) < ReplayEvaluationDatasetAction::REGRESSION_THRESHOLD,
            ]));
        }

        ReplayEvaluationDatasetJob::dispatch(
            teamId: $user->current_team_id,
            datasetId: $datasetId,
            targetProvider: $provider,
            targetModel: $model,
            systemPrompt: is_string($systemPrompt) && $systemPrompt !== '' ? $systemPrompt : null,
            criteria: $criteria,
            judgeModel: is_string($judgeModel) && $judgeModel !== '' ? $judgeModel : null,
            maxCases: $maxCases,
        );

        return Response::text(json_encode([
            'status' => 'queued',
            'dataset_id' => $datasetId,
            'target_provider' => $provider,
            'target_model' => $model,
            'criteria' => $criteria,
            'max_cases' => $maxCases,
            'message' => 'Replay queued. Poll evaluation_run with action=list&dataset_id= for the newest Running/Completed run.',
        ]));
    }
}
