<?php

namespace App\Domain\Assistant\Services;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

/**
 * Produces a compact priority-tiered XML snapshot of a long assistant conversation.
 *
 * When a conversation exceeds the compaction threshold, this service synthesises
 * a 2 KB "resume" message using a cheap model. The snapshot is persisted as a
 * pinned system message; covered messages are marked archived so future context
 * builds exclude them.
 *
 * Priority tiers (budget fractions from context-mode pattern):
 *   P1 (50%): active entity context, last 3 user questions, last 5 tool outcomes
 *   P2 (35%): errors encountered, key decisions, pending actions
 *   P3 (15%): conversation intent, tool usage summary
 */
class ConversationCompactor
{
    public function compact(AssistantConversation $conversation): AssistantMessage
    {
        /** @var Collection<int, AssistantMessage> $messages */
        $messages = $conversation->messages()
            ->whereIn('role', ['user', 'assistant', 'system'])
            ->where(function ($q) {
                $q->whereNull('metadata->is_snapshot')
                    ->orWhere('metadata->is_snapshot', false);
            })
            ->where(function ($q) {
                $q->whereNull('metadata->archived')
                    ->orWhere('metadata->archived', false);
            })
            ->orderBy('created_at')
            ->get();

        if ($messages->isEmpty()) {
            throw new \RuntimeException('No messages to compact.');
        }

        $snapshot = $this->synthesiseSnapshot($conversation, $messages);
        $coveredIds = $messages->pluck('id')->all();

        // Create the pinned snapshot system message
        $snapshotMessage = AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'system',
            'content' => $snapshot,
            'metadata' => [
                'pinned' => true,
                'is_snapshot' => true,
                'snapshot_covers_message_ids' => $coveredIds,
                'compacted_at' => now()->toIso8601String(),
                'covered_count' => count($coveredIds),
            ],
            'created_at' => now(),
        ]);

        // Mark covered messages as archived (kept in DB, excluded from context builds)
        AssistantMessage::whereIn('id', $coveredIds)->update([
            'metadata' => \DB::raw(
                "jsonb_set(coalesce(metadata, '{}'), '{archived}', 'true')",
            ),
        ]);

        Log::info('ConversationCompactor: compacted conversation', [
            'conversation_id' => $conversation->id,
            'covered_messages' => count($coveredIds),
            'snapshot_id' => $snapshotMessage->id,
        ]);

        return $snapshotMessage;
    }

    /**
     * Build the prompt context and call the summariser model.
     */
    private function synthesiseSnapshot(AssistantConversation $conversation, Collection $messages): string
    {
        $userMessages = $messages->where('role', 'user')->values();
        $assistantMessages = $messages->where('role', 'assistant')->values();

        // Extract signals for each priority tier (escaped to prevent prompt injection)
        $lastUserQuestions = $userMessages->slice(-3)->pluck('content')->map(fn ($c) => htmlspecialchars(mb_substr($c, 0, 200), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))->implode("\n- ");
        $lastToolOutcomes = $assistantMessages->filter(fn ($m) => ! empty($m->tool_calls))->slice(-5)->map(function ($m) {
            $calls = is_array($m->tool_calls) ? $m->tool_calls : [];

            return collect($calls)->map(fn ($c) => ($c['name'] ?? 'tool').' → '.mb_substr((string) ($c['result'] ?? ''), 0, 100))->implode(', ');
        })->filter()->implode("\n- ");

        // Errors: assistant messages containing error keywords
        $errors = $assistantMessages->filter(fn ($m) => preg_match('/error|fail|exception|cannot|unable/i', $m->content ?? ''))->slice(-3)->pluck('content')->map(fn ($c) => mb_substr($c, 0, 150))->implode("\n- ");

        // Escape message content so user-supplied text cannot break out of the XML structure
        // in the LLM prompt and cause prompt injection into the produced snapshot.
        $rawHistory = $messages->map(function ($m) {
            $role = htmlspecialchars($m->role, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $body = htmlspecialchars(mb_substr($m->content ?? '', 0, 300), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return "<message role=\"{$role}\">{$body}</message>";
        })->implode("\n");

        $model = config('context_compaction.summarizer_model', 'anthropic/claude-haiku-4-5');
        [$modelProvider, $modelName] = array_pad(explode('/', $model, 2), 2, 'claude-haiku-4-5');

        $systemPrompt = <<<'SYSTEM'
You are a conversation summariser. Produce a compact XML context snapshot under 2000 characters.
Focus on what the user is trying to accomplish and what has been done so far.
SYSTEM;

        $userPrompt = <<<PROMPT
Summarise this assistant conversation into a <context_snapshot> XML block with these sections:
- <p1_active>: The main task/goal, last 3 user questions, last 5 tool outcomes</p1_active>
- <p2_decisions>: Errors encountered, key decisions made, pending actions</p2_decisions>
- <p3_intent>: Overall conversation intent in 1-2 sentences</p3_intent>

Conversation history ({$messages->count()} messages):
{$rawHistory}

Recent user questions:
- {$lastUserQuestions}

Recent tool outcomes:
- {$lastToolOutcomes}

Errors seen:
- {$errors}

Produce only the <context_snapshot> XML block, nothing else.
PROMPT;

        try {
            $response = Prism::text()
                ->using($modelProvider, $modelName)
                ->withSystemPrompt($systemPrompt)
                ->withPrompt($userPrompt)
                ->withMaxTokens(600)
                ->generate();

            $text = $response->text;

            // Reject responses that don't conform to the expected XML structure
            // to prevent a prompt-injected snapshot from persisting.
            if (! str_contains($text, '<context_snapshot>') || ! str_contains($text, '</context_snapshot>')) {
                Log::warning('ConversationCompactor: LLM returned malformed snapshot, using fallback');

                return $this->fallbackSnapshot($conversation, $messages, $lastUserQuestions);
            }

            return $text;
        } catch (\Throwable $e) {
            Log::warning('ConversationCompactor: LLM synthesis failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackSnapshot($conversation, $messages, $lastUserQuestions);
        }
    }

    /**
     * Deterministic fallback snapshot when the LLM call fails.
     */
    private function fallbackSnapshot(AssistantConversation $conversation, Collection $messages, string $lastUserQuestions): string
    {
        $count = $messages->count();
        $since = $messages->first()?->created_at?->diffForHumans() ?? 'recently';

        return <<<XML
<context_snapshot>
  <p1_active>
    Conversation with {$count} messages starting {$since}.
    Recent questions: {$lastUserQuestions}
  </p1_active>
  <p2_decisions>Context compacted — older messages archived.</p2_decisions>
  <p3_intent>AI assistant session for team operations.</p3_intent>
</context_snapshot>
XML;
    }
}
