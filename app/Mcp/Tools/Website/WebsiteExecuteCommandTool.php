<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\ExecuteWebsiteCommandAction;
use App\Domain\Website\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WebsiteExecuteCommandTool extends Tool
{
    protected string $name = 'website_execute_command';

    protected string $description = 'Execute a management command on a website using its assigned managing crew.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()
                ->description('Website UUID')
                ->required(),
            'command' => $schema->string()
                ->description('The command or instruction for the managing crew to execute')
                ->required(),
            'page_id' => $schema->string()
                ->description('Optional page UUID to scope the command to a specific page'),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::with('pages')->find($request->get('website_id'));

        if (! $website) {
            return Response::error('Website not found.');
        }

        try {
            $execution = app(ExecuteWebsiteCommandAction::class)->execute(
                website: $website,
                command: $request->get('command'),
                pageId: $request->get('page_id') ?: null,
            );
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text(json_encode([
            'crew_execution_id' => $execution->id,
            'crew_execution_url' => url('/crews/'.$website->managing_crew_id.'/execute'),
            'status' => $execution->status->value,
        ]));
    }
}
