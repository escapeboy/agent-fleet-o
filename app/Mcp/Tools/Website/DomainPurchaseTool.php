<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Actions\Domain\PurchaseDomainAction;
use App\Domain\Website\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DomainPurchaseTool extends Tool
{
    protected string $name = 'domain_purchase';

    protected string $description = 'Purchase a domain via Namecheap and attach it to a website. Charges the Namecheap account. Requires a Namecheap credential on the team.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()->description('UUID of the website to attach the domain to'),
            'domain' => $schema->string()->description('Domain name to purchase (e.g. example.com)'),
            'years' => $schema->integer()->description('Number of years to register (1-10)')->default(1),
            'contact' => $schema->object()->description('Registrant contact information')->properties([
                'first_name' => $schema->string(),
                'last_name' => $schema->string(),
                'address1' => $schema->string(),
                'city' => $schema->string(),
                'state_province' => $schema->string(),
                'postal_code' => $schema->string(),
                'country' => $schema->string()->description('Two-letter ISO country code (e.g. US)'),
                'phone' => $schema->string()->description('Phone in +NNN.NNNNNNNN format'),
                'email_address' => $schema->string(),
            ]),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::findOrFail($request->get('website_id'));
        $team = Team::findOrFail($website->team_id);

        $contact = array_merge(
            (array) $request->get('contact', []),
            ['years' => $request->get('years', 1)],
        );

        $result = (new PurchaseDomainAction)->execute($team, $website, $request->get('domain'), $contact);

        return Response::text(json_encode($result, JSON_PRETTY_PRINT));
    }
}
