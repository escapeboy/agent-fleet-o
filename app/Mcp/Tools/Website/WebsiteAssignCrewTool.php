<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\AssignWebsiteCrewAction;
use App\Domain\Website\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WebsiteAssignCrewTool extends Tool
{
    protected string $name = 'website_assign_crew';

    protected string $description = 'Assign or unassign a managing crew to a website.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()
                ->description('Website UUID')
                ->required(),
            'crew_id' => $schema->string()
                ->description('Crew UUID to assign, or omit/null to unassign'),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::find($request->get('website_id'));

        if (! $website) {
            return Response::error('Website not found.');
        }

        try {
            app(AssignWebsiteCrewAction::class)->execute($website, $request->get('crew_id') ?: null);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        $website->refresh()->load('managingCrew');

        return Response::text(json_encode([
            'website_id' => $website->id,
            'managing_crew_id' => $website->managing_crew_id,
            'managing_crew_name' => $website->managingCrew?->name,
        ]));
    }
}
