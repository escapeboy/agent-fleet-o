<?php

namespace App\Mcp\Tools\Admin;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\DeploymentMode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Stripe\StripeClient;

#[IsDestructive]
class AdminBillingRefundTool extends Tool
{
    protected string $name = 'admin_billing_refund';

    protected string $description = 'Issue a Stripe refund for a specific payment intent on a team. Super admin only.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'team_id' => $schema->string()
                ->description('UUID of the team')
                ->required(),
            'payment_intent_id' => $schema->string()
                ->description('Stripe payment_intent ID to refund (e.g. pi_...)')
                ->required(),
            'amount_cents' => $schema->integer()
                ->description('Amount in cents to refund. Leave empty for full refund.')
                ->default(0),
            'reason' => $schema->string()
                ->description('Refund reason')
                ->enum(['requested_by_customer', 'duplicate', 'fraudulent'])
                ->default('requested_by_customer'),
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

        $stripe = new StripeClient(config('cashier.secret'));
        $params = [
            'payment_intent' => $request->get('payment_intent_id'),
            'reason' => $request->get('reason', 'requested_by_customer'),
        ];

        $amountCents = (int) $request->get('amount_cents', 0);
        if ($amountCents > 0) {
            $params['amount'] = $amountCents;
        }

        $refund = $stripe->refunds->create($params);

        return Response::text(json_encode([
            'success' => true,
            'refund_id' => $refund->id,
            'amount_cents' => $refund->amount,
            'status' => $refund->status,
            'reason' => $refund->reason,
        ]));
    }
}
