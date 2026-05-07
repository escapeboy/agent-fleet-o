<?php

namespace App\Livewire\Admin;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Models\CircuitBreakerState;
use App\Infrastructure\AI\Models\LlmRequestLog;
use App\Infrastructure\AI\Models\SemanticCacheEntry;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class AiControlCenterPage extends Component
{
    public function render()
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        $since24h = now()->subDay();

        $stats = Cache::remember('admin.ai_control_center', 30, function () use ($since24h) {
            // LLM request stats
            $totalRequests = LlmRequestLog::where('created_at', '>=', $since24h)->count();
            $successRequests = LlmRequestLog::where('created_at', '>=', $since24h)
                ->where('status', 'success')->count();
            $totalCostCredits = LlmRequestLog::where('created_at', '>=', $since24h)->sum('cost_credits');
            $avgLatencyMs = LlmRequestLog::where('created_at', '>=', $since24h)
                ->where('status', 'success')->avg('latency_ms');

            // Cost by provider
            $costByProvider = LlmRequestLog::where('created_at', '>=', $since24h)
                ->selectRaw('provider, SUM(cost_credits) as total_cost, COUNT(*) as request_count')
                ->groupBy('provider')
                ->orderByDesc('total_cost')
                ->get();

            // Usage by model
            $usageByModel = LlmRequestLog::where('created_at', '>=', $since24h)
                ->selectRaw('model, COUNT(*) as request_count, SUM(cost_credits) as total_cost, AVG(latency_ms) as avg_latency')
                ->groupBy('model')
                ->orderByDesc('request_count')
                ->limit(10)
                ->get();

            // Top teams by spend (last 24h)
            $topTeamsBySpend = LlmRequestLog::where('created_at', '>=', $since24h)
                ->whereNotNull('team_id')
                ->selectRaw('team_id, SUM(cost_credits) as total_cost, COUNT(*) as request_count')
                ->groupBy('team_id')
                ->orderByDesc('total_cost')
                ->limit(10)
                ->get()
                ->map(function ($row) {
                    $row->team_name = Team::withoutGlobalScopes()->find($row->team_id)->name ?? 'Unknown';

                    return $row;
                });

            // Circuit breaker states (keyed by agent name for display)
            $circuitBreakers = CircuitBreakerState::withoutGlobalScopes()
                ->with('agent')
                ->orderByDesc('failure_count')
                ->limit(10)
                ->get()
                ->keyBy(fn ($cb) => $cb->agent->name ?? substr($cb->agent_id ?? '', 0, 8) ?: 'Unknown');

            // Semantic cache stats
            $cacheTotal = SemanticCacheEntry::count();
            $cacheUsed = SemanticCacheEntry::where('hit_count', '>', 0)->count();
            $totalHits = SemanticCacheEntry::sum('hit_count');

            // Error breakdown
            $errorsByType = LlmRequestLog::where('created_at', '>=', $since24h)
                ->where('status', 'error')
                ->selectRaw('error, COUNT(*) as count')
                ->groupBy('error')
                ->orderByDesc('count')
                ->limit(5)
                ->get();

            // Tokens by provider
            $tokensByProvider = LlmRequestLog::where('created_at', '>=', $since24h)
                ->selectRaw('provider, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens')
                ->groupBy('provider')
                ->get();

            return compact(
                'totalRequests', 'successRequests', 'totalCostCredits', 'avgLatencyMs',
                'costByProvider', 'usageByModel', 'topTeamsBySpend',
                'circuitBreakers', 'cacheTotal', 'cacheUsed', 'totalHits',
                'errorsByType', 'tokensByProvider',
            );
        });

        $errorRate = $stats['totalRequests'] > 0
            ? round((($stats['totalRequests'] - $stats['successRequests']) / $stats['totalRequests']) * 100, 1)
            : 0;

        return view('livewire.admin.ai-control-center-page', array_merge($stats, [
            'errorRate' => $errorRate,
        ]))->layout('layouts.app', ['header' => 'AI Control Center']);
    }
}
