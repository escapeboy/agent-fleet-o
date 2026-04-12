<?php

namespace App\Domain\Assistant\Services;

use App\Domain\Assistant\DTOs\MemorySummarySchema;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

/**
 * Compresses long conversations into structured memory snapshots.
 *
 * Uses AgentScope-inspired SummarySchema pattern: the LLM outputs a structured
 * JSON object with constrained fields instead of free-form text. This produces
 * more consistent, parseable, and token-efficient snapshots.
 *
 * Priority tiers (budget fractions):
 *   P1 (50%): task overview + current state + last tool outcomes
 *   P2 (35%): key discoveries, errors, decisions
 *   P3 (15%): next steps + context to preserve
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

        $schema = $this->synthesiseStructuredSnapshot($conversation, $messages);
        $snapshotContent = $schema->toContextString();
        $coveredIds = $messages->pluck('id')->all();

        $snapshotMessage = AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'system',
            'content' => $snapshotContent,
            'metadata' => [
                'pinned' => true,
                'is_snapshot' => true,
                'snapshot_covers_message_ids' => $coveredIds,
                'compacted_at' => now()->toIso8601String(),
                'covered_count' => count($coveredIds),
                'compression_schema' => $schema->toArray(),
                'estimated_tokens' => $schema->estimateTokens(),
            ],
            'created_at' => now(),
        ]);

        // Archive covered messages
        // Use CASE to guard against metadata being a JSON array (not object),
        // which would cause jsonb_set to fail with "path element is not an integer".
        AssistantMessage::whereIn('id', $coveredIds)->update([
            'metadata' => \DB::raw(
                "jsonb_set(CASE WHEN jsonb_typeof(coalesce(metadata, '{}')) = 'object' THEN coalesce(metadata, '{}') ELSE '{}'::jsonb END, '{archived}', 'true')",
            ),
        ]);

        // Track compression stats on conversation
        $this->updateCompressionStats($conversation, count($coveredIds), $schema->estimateTokens());

        Log::info('ConversationCompactor: structured compression complete', [
            'conversation_id' => $conversation->id,
            'covered_messages' => count($coveredIds),
            'snapshot_id' => $snapshotMessage->id,
            'estimated_tokens' => $schema->estimateTokens(),
        ]);

        return $snapshotMessage;
    }

    private function synthesiseStructuredSnapshot(AssistantConversation $conversation, Collection $messages): MemorySummarySchema
    {
        $userMessages = $messages->where('role', 'user')->values();
        $assistantMessages = $messages->where('role', 'assistant')->values();

        $lastUserQuestions = $userMessages->slice(-3)->pluck('content')
            ->map(fn ($c) => htmlspecialchars(mb_substr($c, 0, 200), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            ->implode("\n- ");

        $lastToolOutcomes = $assistantMessages->filter(fn ($m) => ! empty($m->tool_calls))
            ->slice(-5)->map(function ($m) {
                $calls = is_array($m->tool_calls) ? $m->tool_calls : [];

                return collect($calls)->map(fn ($c) => ($c['name'] ?? 'tool').' → '.mb_substr((string) ($c['result'] ?? ''), 0, 100))->implode(', ');
            })->filter()->implode("\n- ");

        $errors = $assistantMessages
            ->filter(fn ($m) => preg_match('/error|fail|exception|cannot|unable/i', $m->content ?? ''))
            ->slice(-3)->pluck('content')
            ->map(fn ($c) => mb_substr($c, 0, 150))
            ->implode("\n- ");

        $rawHistory = $messages->map(function ($m) {
            $role = htmlspecialchars($m->role, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $body = htmlspecialchars(mb_substr($m->content ?? '', 0, 300), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return "<message role=\"{$role}\">{$body}</message>";
        })->implode("\n");

        $model = config('context_compaction.summarizer_model', 'anthropic/claude-haiku-4-5');
        [$modelProvider, $modelName] = array_pad(explode('/', $model, 2), 2, 'claude-haiku-4-5');

        // If a dedicated compaction API key is configured, inject it for this call.
        // PHP-FPM handles one request per worker — config override is process-safe.
        $summariserApiKey = config('context_compaction.summarizer_api_key');
        if ($summariserApiKey) {
            config(["prism.providers.{$modelProvider}.api_key" => $summariserApiKey]);
        }

        $schemaJson = json_encode(MemorySummarySchema::jsonSchema(), JSON_PRETTY_PRINT);

        $systemPrompt = <<<'SYSTEM'
You are a conversation memory compressor. Extract the essential information from a conversation into a structured JSON object. Be specific and preserve entity names, IDs, and technical details.
SYSTEM;

        $userPrompt = <<<PROMPT
Compress this assistant conversation ({$messages->count()} messages) into structured memory.

Output a JSON object matching this schema exactly:
{$schemaJson}

Conversation history:
{$rawHistory}

Recent user questions:
- {$lastUserQuestions}

Recent tool outcomes:
- {$lastToolOutcomes}

Errors seen:
- {$errors}

Output ONLY valid JSON, no markdown fences or commentary.
PROMPT;

        try {
            $response = Prism::text()
                ->using($modelProvider, $modelName)
                ->withSystemPrompt($systemPrompt)
                ->withPrompt($userPrompt)
                ->withMaxTokens(800)
                ->generate();

            $text = trim($response->text);

            // Strip markdown fences if present
            if (str_starts_with($text, '```')) {
                $text = preg_replace('/^```(?:json)?\s*/', '', $text);
                $text = preg_replace('/\s*```$/', '', $text);
            }

            $data = json_decode($text, true);

            if (! is_array($data) || ! isset($data['task_overview'])) {
                Log::warning('ConversationCompactor: LLM returned invalid JSON, using fallback');

                return $this->fallbackSchema($conversation, $messages, $lastUserQuestions);
            }

            return MemorySummarySchema::fromArray($data);
        } catch (\Throwable $e) {
            Log::warning('ConversationCompactor: LLM synthesis failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackSchema($conversation, $messages, $lastUserQuestions);
        }
    }

    private function fallbackSchema(AssistantConversation $conversation, Collection $messages, string $lastUserQuestions): MemorySummarySchema
    {
        $count = $messages->count();
        $since = $messages->first()?->created_at?->diffForHumans() ?? 'recently';

        return new MemorySummarySchema(
            taskOverview: "Conversation with {$count} messages starting {$since}. Recent questions: {$lastUserQuestions}",
            currentState: 'Context compacted — older messages archived.',
            keyDiscoveries: [],
            nextSteps: [],
            contextToPreserve: '',
        );
    }

    private function updateCompressionStats(AssistantConversation $conversation, int $coveredCount, int $estimatedTokens): void
    {
        $metadata = $conversation->metadata ?? [];
        $stats = $metadata['compression_stats'] ?? ['total_compressions' => 0, 'total_messages_compressed' => 0];

        $stats['total_compressions'] = ($stats['total_compressions'] ?? 0) + 1;
        $stats['total_messages_compressed'] = ($stats['total_messages_compressed'] ?? 0) + $coveredCount;
        $stats['last_compressed_at'] = now()->toIso8601String();
        $stats['last_snapshot_tokens'] = $estimatedTokens;

        $metadata['compression_stats'] = $stats;
        $conversation->update(['metadata' => $metadata]);
    }
}
