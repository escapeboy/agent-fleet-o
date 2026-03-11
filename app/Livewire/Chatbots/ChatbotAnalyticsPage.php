<?php

namespace App\Livewire\Chatbots;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Chatbot\Models\ChatbotSession;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class ChatbotAnalyticsPage extends Component
{
    public Chatbot $chatbot;

    public int $days = 30;

    public function mount(Chatbot $chatbot): void
    {
        $this->chatbot = $chatbot;
    }

    public function render()
    {
        $team = auth()->user()->currentTeam;

        if (! ($team->settings['chatbot_enabled'] ?? false)) {
            return $this->redirect(route('dashboard'));
        }

        $since = now()->subDays($this->days);

        $totalSessions = ChatbotSession::where('chatbot_id', $this->chatbot->id)
            ->where('created_at', '>=', $since)
            ->count();

        $stats = ChatbotMessage::where('chatbot_id', $this->chatbot->id)
            ->where('role', 'assistant')
            ->where('created_at', '>=', $since)
            ->selectRaw('
                COUNT(*) as total,
                AVG(confidence) as avg_confidence,
                AVG(latency_ms) as avg_latency_ms,
                SUM(CASE WHEN was_escalated THEN 1 ELSE 0 END) as escalated_count
            ')
            ->first();

        $totalMessages = (int) ($stats->total ?? 0);
        $escalatedCount = (int) ($stats->escalated_count ?? 0);
        $escalationRate = $totalMessages > 0 ? round($escalatedCount / $totalMessages * 100, 1) : 0;
        $containmentRate = 100 - $escalationRate;

        // Sessions per day for spark chart
        $sessionsByDay = ChatbotSession::where('chatbot_id', $this->chatbot->id)
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->pluck('count', 'date');

        // Fill in all days including zeros
        $dailySeries = [];
        for ($i = $this->days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dailySeries[] = ['date' => $date, 'count' => (int) ($sessionsByDay[$date] ?? 0)];
        }

        // Low-confidence messages (unanswered questions) — no RAG sources found AND below threshold
        $lowConfidenceMessages = ChatbotMessage::where('chatbot_id', $this->chatbot->id)
            ->where('role', 'assistant')
            ->where('created_at', '>=', $since)
            ->where('confidence', '<', (float) $this->chatbot->confidence_threshold)
            ->whereNull('metadata->sources')
            ->with('session')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('livewire.chatbots.chatbot-analytics-page', [
            'totalSessions' => $totalSessions,
            'totalMessages' => $totalMessages,
            'avgConfidence' => $stats->avg_confidence ? round((float) $stats->avg_confidence * 100, 1) : null,
            'avgLatencyMs' => $stats->avg_latency_ms ? (int) $stats->avg_latency_ms : null,
            'escalationRate' => $escalationRate,
            'containmentRate' => $containmentRate,
            'dailySeries' => $dailySeries,
            'lowConfidenceMessages' => $lowConfidenceMessages,
        ])->layout('layouts.app', ['header' => $this->chatbot->name.' — Analytics']);
    }
}
