<?php

namespace App\Mcp\Tools\Budget;

use App\Domain\Budget\Actions\CheckUpstreamCreditRunwayAction;
use App\Domain\Shared\Services\DeploymentMode;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class UpstreamCreditRunwayTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'budget_upstream_runway';

    protected string $description = 'Platform owner view: forecasted upstream (platform-funded) credit runway per configured sub-program/provider — remaining credits, 7d daily burn, and estimated days until depletion. Read-only; does not send alerts.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        if (app(DeploymentMode::class)->isCloud() && ! auth()->user()?->is_super_admin) {
            return $this->permissionDeniedError('Access denied: super admin privileges required.');
        }

        $summaries = app(CheckUpstreamCreditRunwayAction::class)->execute(dryRun: true);

        return Response::text(json_encode([
            'budgets_evaluated' => count($summaries),
            'runway' => $summaries,
        ]));
    }
}
