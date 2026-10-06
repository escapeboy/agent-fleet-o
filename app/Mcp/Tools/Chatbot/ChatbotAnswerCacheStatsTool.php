<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotAnswerCacheEntry;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Chatbot\Services\ChatbotAnswerCache;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ChatbotAnswerCacheStatsTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'chatbot_answer_cache_stats';

    protected string $description = 'Semantic answer cache metrics for a chatbot over the last N days: hit rate, judge decisions, skip reasons, latency on hit vs miss, saved tokens and credits, entries held.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()
                ->description('Chatbot UUID or slug')
                ->required(),
            'days' => $schema->integer()
                ->description('Days to look back (default 7, max 90)')
                ->default(7),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'chatbot_id' => 'required|string',
            'days' => 'sometimes|integer|min:1|max:90',
        ]);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $idOrSlug = $validated['chatbot_id'];
        $chatbot = Chatbot::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where(Str::isUuid($idOrSlug) ? 'id' : 'slug', $idOrSlug)
            ->first();

        if (! $chatbot) {
            return $this->notFoundError('chatbot', $idOrSlug);
        }

        $days = (int) ($validated['days'] ?? 7);
        $status = [];
        $decisions = [];
        $skipReasons = [];
        $latency = ['served' => [], 'miss' => []];
        $savedTokens = 0;
        $savedCredits = 0;

        $messages = ChatbotMessage::withoutGlobalScopes()
            ->where('team_id', $chatbot->team_id)
            ->where('chatbot_id', $chatbot->id)
            ->where('role', 'assistant')
            ->where('created_at', '>=', now()->subDays($days))
            ->select(['metadata', 'latency_ms'])
            ->cursor();

        foreach ($messages as $message) {
            $meta = $message->metadata['answer_cache'] ?? null;
            if (! is_array($meta)) {
                continue;
            }
            $s = $meta['status'] ?? 'unknown';
            $status[$s] = ($status[$s] ?? 0) + 1;
            if ($s === 'skipped') {
                $r = $meta['reason'] ?? 'unknown';
                $skipReasons[$r] = ($skipReasons[$r] ?? 0) + 1;

                continue;
            }
            $d = $meta['decision'] ?? 'unknown';
            $decisions[$d] = ($decisions[$d] ?? 0) + 1;
            $latency[in_array($s, ['hit', 'combine'], true) ? 'served' : 'miss'][] = (int) $message->latency_ms;
            $savedTokens += (int) ($meta['saved_tokens'] ?? 0);
            $savedCredits += (int) ($meta['saved_cost_credits'] ?? 0);
        }

        $served = ($status['hit'] ?? 0) + ($status['combine'] ?? 0);
        $eligible = $served + ($status['miss'] ?? 0);
        $avg = fn (array $v) => $v === [] ? null : (int) round(array_sum($v) / count($v));

        return Response::text(json_encode([
            'chatbot_id' => $chatbot->id,
            'period_days' => $days,
            'settings' => app(ChatbotAnswerCache::class)->settings($chatbot),
            'platform_enabled' => (bool) config('chatbot_answer_cache.enabled', false),
            'by_status' => $status,
            'hit_rate_pct' => $eligible > 0 ? round($served / $eligible * 100, 1) : null,
            'judge_decisions' => $decisions,
            'skip_reasons' => $skipReasons,
            'avg_latency_ms' => ['served' => $avg($latency['served']), 'miss' => $avg($latency['miss'])],
            'saved_tokens' => $savedTokens,
            'saved_cost_credits' => $savedCredits,
            'entries' => ChatbotAnswerCacheEntry::withoutGlobalScopes()
                ->where('team_id', $chatbot->team_id)
                ->where('chatbot_id', $chatbot->id)
                ->where('generation', (int) $chatbot->answer_cache_generation)
                ->count(),
        ]));
    }
}
