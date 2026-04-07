<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Actions\Domain\CheckDomainAvailabilityAction;
use App\Domain\Website\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class DomainCheckTool extends Tool
{
    protected string $name = 'domain_check';

    protected string $description = 'Check whether a domain name is available for registration via Namecheap. Requires a Namecheap credential on the team.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()->description('UUID of the website'),
            'domain' => $schema->string()->description('Domain name to check (e.g. example.com)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::findOrFail($request->get('website_id'));
        $team = Team::findOrFail($website->team_id);

        $result = (new CheckDomainAvailabilityAction)->execute($team, $request->get('domain'));

        return Response::text(json_encode($result, JSON_PRETTY_PRINT));
    }
}
