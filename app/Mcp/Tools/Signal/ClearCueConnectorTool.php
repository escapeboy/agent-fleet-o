<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Models\Signal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP tool for managing the ClearCue GTM signal connector.
 *
 * Provides setup instructions, webhook URL, and signal statistics
 * for the ClearCue buyer intent integration.
 */
#[IsReadOnly]
class ClearCueConnectorTool extends Tool
{
    protected string $name = 'clearcue_connector_manage';

    protected string $description = 'Manage the ClearCue GTM signal connector. Get webhook setup instructions, check connector status, and view recent ClearCue intent signals. ClearCue monitors LinkedIn, job postings, conferences, and competitor activity to surface buying intent.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action to perform: get_setup_instructions | get_status | list_recent_signals')
                ->enum(['get_setup_instructions', 'get_status', 'list_recent_signals'])
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => 'required|string|in:get_setup_instructions,get_status,list_recent_signals',
        ]);

        try {
            return match ($validated['action']) {
                'get_setup_instructions' => $this->getSetupInstructions(),
                'get_status'             => $this->getStatus(),
                'list_recent_signals'    => $this->listRecentSignals(),
            };
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    private function getSetupInstructions(): Response
    {
        $webhookUrl = url('/api/signals/clearcue');
        $configured = (bool) config('services.clearcue.webhook_secret');

        return Response::text(json_encode([
            'connector'    => 'ClearCue',
            'webhook_url'  => $webhookUrl,
            'configured'   => $configured,
            'plan_required' => 'Pro (€199/month) or higher — webhooks not available on Starter plan',
            'steps' => [
                '1. Sign up or log in at https://app.clearcue.ai',
                '2. Upgrade to Pro plan if not already done',
                '3. Create an Audience (ICP definition) in ClearCue',
                '4. Create a List based on your Audience',
                '5. Go to List Settings → Integrations → Webhooks → Add Webhook',
                '6. Set Endpoint URL to: '.$webhookUrl,
                '7. Copy the signing secret from the ClearCue dashboard',
                '8. Add to your .env: CLEARCUE_WEBHOOK_SECRET=<signing_secret>',
                '9. ClearCue will now push buyer intent signals to FleetQ automatically',
            ],
            'env_var'              => 'CLEARCUE_WEBHOOK_SECRET',
            'signature_header'     => 'X-ClearCue-Signature (exact name TBC from dashboard)',
            'mcp_integration'      => [
                'description' => 'ClearCue also offers an MCP server for on-demand signal queries',
                'endpoint'    => 'https://api.tools.clearcue.ai/mcp/sse',
                'auth'        => 'OAuth 2.1',
                'setup'       => 'Create a Tool record in FleetQ with type=mcp_http pointing to the MCP endpoint, link OAuth credentials via Credential model',
            ],
            'signal_types' => [
                'social'          => 'LinkedIn post engagement, competitor interactions',
                'hiring'          => 'Job postings for relevant roles',
                'events'          => 'Conference/event RSVPs and appearances',
                'news'            => 'Press mentions, funding announcements',
                'evaluation'      => 'Competitor research, review site visits',
                'purchase_intent' => 'Demo requests, pricing page views (highest value)',
            ],
            'trigger_rule_example' => [
                'description' => 'Fire when a hot prospect shows evaluation-level intent',
                'conditions'  => [
                    ['field' => 'payload.signal_category', 'operator' => 'eq', 'value' => 'evaluation'],
                    ['field' => 'payload.signal_frequency', 'operator' => 'gte', 'value' => 3],
                ],
            ],
        ]));
    }

    private function getStatus(): Response
    {
        $configured = (bool) config('services.clearcue.webhook_secret');

        $stats = Signal::where('source_type', 'clearcue')
            ->selectRaw('COUNT(*) as total, MAX(received_at) as last_received_at')
            ->first();

        $last24h = Signal::where('source_type', 'clearcue')
            ->where('received_at', '>=', now()->subDay())
            ->count();

        return Response::text(json_encode([
            'configured'      => $configured,
            'status'          => $configured ? ($last24h > 0 ? 'active' : 'configured') : 'not_configured',
            'total_signals'   => (int) ($stats?->total ?? 0),
            'last_received_at' => $stats?->last_received_at,
            'signals_last_24h' => $last24h,
            'webhook_url'     => url('/api/signals/clearcue'),
        ]));
    }

    private function listRecentSignals(): Response
    {
        $signals = Signal::where('source_type', 'clearcue')
            ->select(['id', 'source_identifier', 'score', 'tags', 'payload', 'received_at'])
            ->latest('received_at')
            ->limit(20)
            ->get()
            ->map(fn ($s) => [
                'id'                => $s->id,
                'company'           => $s->payload['company_name'] ?? null,
                'person'            => $s->payload['person_name'] ?? null,
                'signal_type'       => $s->payload['signal_type'] ?? null,
                'signal_category'   => $s->payload['signal_category'] ?? null,
                'score'             => $s->score,
                'tags'              => $s->tags,
                'received_at'       => $s->received_at,
            ]);

        return Response::text(json_encode([
            'signals' => $signals,
            'count'   => $signals->count(),
        ]));
    }
}
