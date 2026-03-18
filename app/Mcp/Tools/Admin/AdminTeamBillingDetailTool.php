<?php

namespace App\Mcp\Tools\Admin;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\DeploymentMode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class AdminTeamBillingDetailTool extends Tool
{
    protected string $name = 'admin_team_billing_detail';

    protected string $description = 'Get billing details for a specific team: Stripe customer, subscription status, plan, payment method, and recent invoices. Super admin only.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'team_id' => $schema->string()
                ->description('UUID of the team')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        if (app(DeploymentMode::class)->isCloud() && ! auth()->user()?->is_super_admin) {
            return Response::error('Access denied: super admin privileges required.');
        }

        $team = Team::withoutGlobalScopes()->findOrFail($request->get('team_id'));
        $sub = $team->subscription('default');

        $invoices = [];
        if ($team->stripe_id) {
            try {
                $invoices = $team->invoices(true, ['limit' => 5])->map(fn ($inv) => [
                    'id' => $inv->id,
                    'number' => $inv->number,
                    'date' => $inv->date()->format('Y-m-d'),
                    'total' => $inv->total(),
                    'status' => $inv->asStripeInvoice()->status,
                ])->toArray();
            } catch (\Throwable) {
                $invoices = [];
            }
        }

        return Response::text(json_encode([
            'team_id' => $team->id,
            'name' => $team->name,
            'plan' => $team->plan,
            'stripe_id' => $team->stripe_id,
            'pm_type' => $team->pm_type,
            'pm_last_four' => $team->pm_last_four,
            'subscription' => $sub ? [
                'status' => $sub->stripe_status,
                'trial_ends_at' => $sub->trial_ends_at?->toIso8601String(),
                'ends_at' => $sub->ends_at?->toIso8601String(),
                'on_grace_period' => $sub->onGracePeriod(),
                'cancelled' => $sub->cancelled(),
            ] : null,
            'recent_invoices' => $invoices,
        ]));
    }
}
