<?php

namespace App\Mcp\Tools\Evaluation;

use App\Domain\Evaluation\Actions\CreateEvaluationDatasetAction;
use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Evaluation\Models\EvaluationDataset;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class EvaluationDatasetManageTool extends Tool
{
    protected string $name = 'evaluation_dataset_manage';

    protected string $description = 'Manage evaluation datasets. Actions: list, get, create, add_case, delete.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: list, get, create, add_case, delete')
                ->enum(['list', 'get', 'create', 'add_case', 'delete'])
                ->required(),
            'dataset_id' => $schema->string()
                ->description('Dataset ID (for get, add_case, delete)'),
            'name' => $schema->string()
                ->description('Dataset name (for create)'),
            'description' => $schema->string()
                ->description('Dataset description (for create)'),
            'input' => $schema->string()
                ->description('Case input text (for add_case)'),
            'expected_output' => $schema->string()
                ->description('Expected output (for add_case)'),
            'context' => $schema->string()
                ->description('Ground truth context (for add_case)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $action = $request->get('action');
        $teamId = auth()->user()?->currentTeam?->id;

        return match ($action) {
            'list' => $this->listDatasets(),
            'get' => $this->getDataset($request->get('dataset_id')),
            'create' => $this->createDataset($teamId, $request),
            'add_case' => $this->addCase($teamId, $request),
            'delete' => $this->deleteDataset($request->get('dataset_id')),
            default => Response::text(json_encode(['error' => "Unknown action: {$action}"])),
        };
    }

    private function listDatasets(): Response
    {
        $datasets = EvaluationDataset::query()
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get(['id', 'name', 'description', 'case_count', 'created_at']);

        return Response::text(json_encode(['datasets' => $datasets->toArray()]));
    }

    private function getDataset(?string $id): Response
    {
        if (! $id) {
            return Response::text(json_encode(['error' => 'dataset_id required']));
        }

        $teamId = auth()->user()?->currentTeam?->id;
        $dataset = $teamId ? EvaluationDataset::withoutGlobalScopes()->where('team_id', $teamId)->with('cases:id,dataset_id,input,expected_output')->find($id) : null;
        if (! $dataset) {
            return Response::text(json_encode(['error' => 'Dataset not found']));
        }

        return Response::text(json_encode($dataset->toArray()));
    }

    private function createDataset(?string $teamId, Request $request): Response
    {
        $dataset = app(CreateEvaluationDatasetAction::class)->execute(
            teamId: $teamId ?? '',
            name: $request->get('name', 'Untitled Dataset'),
            description: $request->get('description'),
        );

        return Response::text(json_encode(['id' => $dataset->id, 'name' => $dataset->name]));
    }

    private function addCase(?string $teamId, Request $request): Response
    {
        $datasetId = $request->get('dataset_id');
        if (! $datasetId) {
            return Response::text(json_encode(['error' => 'dataset_id required']));
        }

        $datasetTeamId = auth()->user()?->currentTeam?->id;
        $dataset = $datasetTeamId ? EvaluationDataset::withoutGlobalScopes()->where('team_id', $datasetTeamId)->find($datasetId) : null;
        if (! $dataset) {
            return Response::text(json_encode(['error' => 'Dataset not found']));
        }

        $case = EvaluationCase::create([
            'dataset_id' => $datasetId,
            'team_id' => $teamId ?? $dataset->team_id,
            'input' => $request->get('input', ''),
            'expected_output' => $request->get('expected_output'),
            'context' => $request->get('context'),
        ]);

        $dataset->increment('case_count');

        return Response::text(json_encode(['case_id' => $case->id]));
    }

    private function deleteDataset(?string $id): Response
    {
        if (! $id) {
            return Response::text(json_encode(['error' => 'dataset_id required']));
        }

        $teamId = auth()->user()?->currentTeam?->id;
        $dataset = $teamId ? EvaluationDataset::withoutGlobalScopes()->where('team_id', $teamId)->find($id) : null;
        if (! $dataset) {
            return Response::text(json_encode(['error' => 'Dataset not found']));
        }

        $dataset->delete();

        return Response::text(json_encode(['deleted' => true]));
    }
}
