<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Signal\Jobs\EvaluateContactRiskJob;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ForceReevaluateContactRiskTool extends Tool
{
    protected string $name = 'contact_risk_reevaluate';

    protected string $description = 'Force a fresh risk score evaluation for a contact identity by dispatching EvaluateContactRiskJob.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'contact_id' => $schema->string()
                ->description('Contact identity UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $contact = ContactIdentity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('contact_id'));

        if (! $contact) {
            return Response::error('Contact not found.');
        }

        EvaluateContactRiskJob::dispatch($contact->id);

        return Response::text(json_encode([
            'dispatched' => true,
            'contact_id' => $contact->id,
        ]));
    }
}
