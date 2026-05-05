<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ChatbotAnalyticsSummaryTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'chatbot_analytics_summary';

    protected string $description = 'Get analytics summary for a chatbot: sessions, messages, confidence, escalation rate, avg latency over the last N days.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()
                ->description('Chatbot UUID or slug')
                ->required(),
            'days' => $schema->integer()
                ->description('Number of days to look back (default 30, max 90)')
                ->default(30),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! (auth()->user()->currentTeam?->settings['chatbot_enabled'] ?? false)) {
            return $this->failedPreconditionError('Chatbot feature is not enabled for this team.');
        }

        $idOrSlug = $request->get('chatbot_id');
        $chatbot = Chatbot::where('id', $idOrSlug)->orWhere('slug', $idOrSlug)->first();

        if (! $chatbot) {
            return $this->notFoundError('chatbot', $idOrSlug);
        }

        $days = min((int) ($request->get('days', 30)), 90);
        $since = now()->subDays($days);

        $totalSessions = ChatbotSession::where('chatbot_id', $chatbot->id)
            ->where('created_at', '>=', $since)
            ->count();

        $totalMessages = ChatbotMessage::where('chatbot_id', $chatbot->id)
            ->where('role', 'assistant')
            ->where('created_at', '>=', $since)
            ->count();

        $stats = ChatbotMessage::where('chatbot_id', $chatbot->id)
            ->where('role', 'assistant')
            ->where('created_at', '>=', $since)
            ->selectRaw('AVG(confidence) as avg_confidence, AVG(latency_ms) as avg_latency_ms, SUM(CASE WHEN was_escalated THEN 1 ELSE 0 END) as escalated_count')
            ->first();

        $escalationRate = $totalMessages > 0
            ? round(($stats->escalated_count ?? 0) / $totalMessages * 100, 1)
            : 0;

        $containmentRate = 100 - $escalationRate;

        return Response::text(json_encode([
            'chatbot_id' => $chatbot->id,
            'chatbot_name' => $chatbot->name,
            'period_days' => $days,
            'total_sessions' => $totalSessions,
            'total_assistant_messages' => $totalMessages,
            'avg_confidence' => $stats->avg_confidence ? round((float) $stats->avg_confidence, 4) : null,
            'avg_latency_ms' => $stats->avg_latency_ms ? (int) $stats->avg_latency_ms : null,
            'escalated_count' => (int) ($stats->escalated_count ?? 0),
            'escalation_rate_pct' => $escalationRate,
            'containment_rate_pct' => $containmentRate,
        ]));
    }
}
