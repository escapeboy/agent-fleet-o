<?php

namespace App\Mcp\Tools\Admin;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\DeploymentMode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class AdminBillingApplyCreditTool extends Tool
{
    protected string $name = 'admin_billing_apply_credit';

    protected string $description = 'Apply a Stripe balance credit (in cents) to a team. Negative amounts add credit. Super admin only.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'team_id' => $schema->string()
                ->description('UUID of the team')
                ->required(),
            'amount_cents' => $schema->integer()
                ->description('Amount in cents (negative to add credit, e.g. -1000 = €10 credit)')
                ->required(),
            'description' => $schema->string()
                ->description('Description for the balance transaction')
                ->default('Admin credit'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (app(DeploymentMode::class)->isCloud() && ! auth()->user()?->is_super_admin) {
            return Response::error('Access denied: super admin privileges required.');
        }

        $team = Team::withoutGlobalScopes()->findOrFail($request->get('team_id'));

        if (! $team->stripe_id) {
            return Response::text(json_encode(['success' => false, 'error' => 'Team has no Stripe customer ID.']));
        }

        $amountCents = (int) $request->get('amount_cents');
        $description = $request->get('description', 'Admin credit');

        // creditBalance applies a negative amount to add credit
        $team->creditBalance($amountCents, $description);

        return Response::text(json_encode([
            'success' => true,
            'message' => "Credit of {$amountCents} cents applied to team '{$team->name}'.",
            'amount_cents' => $amountCents,
            'description' => $description,
        ]));
    }
}
