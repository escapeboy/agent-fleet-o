<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\DeleteToolsetAction;
use App\Domain\Tool\Models\Toolset;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ToolsetDeleteTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'toolset_delete';

    protected string $description = 'Delete a toolset. Agents using it will lose access to the grouped tools.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'toolset_id' => $schema->string()
                ->description('The toolset UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $toolset = Toolset::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('toolset_id'));

        if (! $toolset) {
            return $this->notFoundError('toolset');
        }

        app(DeleteToolsetAction::class)->execute($toolset);

        return Response::text(json_encode(['deleted' => true, 'id' => $request->get('toolset_id')]));
    }
}
