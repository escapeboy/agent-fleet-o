<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Actions\Domain\ConfigureDnsAction;
use App\Domain\Website\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DomainDnsTool extends Tool
{
    protected string $name = 'domain_dns_configure';

    protected string $description = 'Configure DNS A records for a website\'s custom domain via Namecheap, pointing @ and www to the given IP address.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()->description('UUID of the website'),
            'ip_address' => $schema->string()->description('IP address to point the domain to'),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::findOrFail($request->get('website_id'));
        $team = Team::findOrFail($website->team_id);

        $success = (new ConfigureDnsAction)->execute($team, $website, $request->get('ip_address'));

        return Response::text(json_encode(['success' => $success], JSON_PRETTY_PRINT));
    }
}
