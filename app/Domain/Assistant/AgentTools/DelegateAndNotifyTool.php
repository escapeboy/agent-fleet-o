<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class DelegateAndNotifyTool implements Tool
{
    public function __construct(
        private string $conversationId = '',
    ) {}

    public function name(): string
    {
        return 'delegate_and_notify';
    }

    public function description(): string
    {
        return 'Fire-and-forget: trigger a project run and notify you when the agents finish. Returns immediately without waiting for results.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required()->description('UUID of the project to run'),
            'note' => $schema->string()->description('Optional note to log with this delegation (why you are delegating this)'),
            'input_data_json' => $schema->string()->description('Optional JSON string of input_data to pass to the project run (e.g. {"topic": "AI trends"})'),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $project = Project::where('id', $request->get('project_id'))->first();
            if (! $project) {
                return json_encode(['error' => "Project {$request->get('project_id')} not found."]);
            }

            $inputData = null;
            $inputDataJson = $request->get('input_data_json');
            if ($inputDataJson) {
                $inputData = json_decode($inputDataJson, true);
            }

            $note = $request->get('note');
            if ($note) {
                $inputData = array_merge($inputData ?? [], ['_delegation_note' => $note]);
            }

            $run = app(TriggerProjectRunAction::class)->execute(
                project: $project,
                trigger: 'assistant',
                inputData: $inputData,
            );

            if ($this->conversationId) {
                $run->update(['triggered_by_conversation_id' => $this->conversationId]);
            }

            return json_encode([
                'success' => true,
                'run_id' => $run->id,
                'project' => $project->title,
                'message' => "Agents are working on it. I'll notify you when '{$project->title}' completes.",
                'run_url' => route('projects.show', $project),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
