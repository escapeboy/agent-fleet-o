<?php

namespace App\Domain\Chatbot\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\Chatbot\DTOs\AnswerCacheLookup;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotAnswerCacheEntry;
use App\Domain\Decision\Services\StructuredDecisionPrompt;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Semantic cache of chatbot answers to standalone questions.
 *
 * Key: team_id + chatbot_id + knowledge generation + prompt hash. Distance only
 * orders candidates; a cheap LLM judge decides hit / combine / reject.
 * Design: docs/design/design-chatbot-answer-cache.md (parent repo).
 */
class ChatbotAnswerCache
{
    public const JUDGE_PURPOSE = 'chatbot.answer_cache_judge';

    public const CHECK_PURPOSE = 'chatbot.answer_cache_check';

    /**
     * Bump when ChatbotResponseService::buildChatbotSystemPrompt() or the reply
     * shaping changes, so answers produced by the old prompt stop being served.
     */
    public const PROMPT_VERSION = 1;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * @return array{enabled: bool, ttl_hours: int, max_entries: int}
     */
    public function settings(Chatbot $chatbot): array
    {
        $own = is_array($chatbot->config['answer_cache'] ?? null) ? $chatbot->config['answer_cache'] : [];

        return [
            'enabled' => (bool) ($own['enabled'] ?? false),
            'ttl_hours' => (int) ($own['ttl_hours'] ?? config('chatbot_answer_cache.ttl_hours', 168)),
            'max_entries' => (int) ($own['max_entries'] ?? config('chatbot_answer_cache.max_entries', 500)),
        ];
    }

    public function isEnabled(Chatbot $chatbot): bool
    {
        return (bool) config('chatbot_answer_cache.enabled', false) && $this->settings($chatbot)['enabled'];
    }

    /**
     * Merge settings into chatbots.config.answer_cache. Turning the cache off
     * also drops what it holds, so re-enabling never serves stale answers.
     *
     * @param  array{enabled?: bool, ttl_hours?: int, max_entries?: int}  $changes
     * @return array{enabled: bool, ttl_hours: int, max_entries: int}
     */
    public function updateSettings(Chatbot $chatbot, array $changes): array
    {
        $config = $chatbot->config ?? [];
        $config['answer_cache'] = array_merge(
            is_array($config['answer_cache'] ?? null) ? $config['answer_cache'] : [],
            array_intersect_key($changes, array_flip(['enabled', 'ttl_hours', 'max_entries'])),
        );
        $chatbot->update(['config' => $config]);

        if (($changes['enabled'] ?? null) === false) {
            $this->invalidate($chatbot->id);
        }

        return $this->settings($chatbot);
    }

    /**
     * Why this message must neither be looked up nor stored, or null when it may.
     */
    public function ineligibilityReason(Chatbot $chatbot, string $question, bool $isFollowUp): ?string
    {
        if (! $this->isEnabled($chatbot)) {
            return 'disabled';
        }
        if ($chatbot->workflow_id) {
            return 'workflow';
        }
        if ($isFollowUp) {
            return 'follow_up';
        }
        if (self::containsPersonalData($question)) {
            return 'personal_data';
        }

        /** @var Agent|null $agent */
        $agent = $chatbot->agent;
        if (! $agent) {
            return 'no_agent';
        }
        // Tool calls can pull live, visitor-specific data into the answer.
        if ($agent->tools()->exists() || $agent->toolsets()->exists()) {
            return 'agent_tools';
        }

        return null;
    }

    /**
     * Cheap regex screen for contact data and ID-like numbers. Names are caught
     * later by the store-time check, which never blocks a visitor.
     */
    public static function containsPersonalData(string $text): bool
    {
        if (preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/u', $text)) {
            return true;
        }

        // Phone / ID / card numbers: 9+ digits in one run (separators allowed).
        // "1 000 000 лв" (7 digits) stays a normal question.
        preg_match_all('/\+?\d[\d\s().\/-]*\d/u', $text, $runs);
        foreach ($runs[0] as $run) {
            if (preg_match_all('/\d/', $run) >= 9) {
                return true;
            }
        }

        return false;
    }

    public static function normalize(string $question): string
    {
        $q = mb_strtolower(trim($question));
        $q = (string) preg_replace('/\s+/u', ' ', $q);

        return rtrim($q, " \t?!.…");
    }

    public function promptHash(Chatbot $chatbot): string
    {
        /** @var Agent|null $agent */
        $agent = $chatbot->agent;

        return hash('sha256', json_encode([
            self::PROMPT_VERSION,
            $agent?->id,
            $agent?->updated_at?->toIso8601String(),
            $chatbot->type->value,
            $chatbot->fallback_message,
            (string) $chatbot->confidence_threshold,
        ]));
    }

    /**
     * @param  float[]  $vector  the query embedding RAG also uses
     */
    public function lookup(Chatbot $chatbot, string $question, array $vector, string $promptHash, int $generation): AnswerCacheLookup
    {
        $startedAt = microtime(true);
        $elapsed = fn (): int => (int) ((microtime(true) - $startedAt) * 1000);

        $exact = $this->scopedQuery($chatbot, $generation, $promptHash)
            ->where('question_hash', hash('sha256', self::normalize($question)))
            ->first();

        if ($exact) {
            $this->recordHit([$exact]);

            return new AnswerCacheLookup('exact', $exact->answer, [$exact], $elapsed());
        }

        $candidates = $this->candidates($chatbot, $generation, $promptHash, $vector);
        if ($candidates === []) {
            return new AnswerCacheLookup('none', lookupMs: $elapsed());
        }

        $judgeStartedAt = microtime(true);
        $verdict = $this->judge($chatbot, $question, $candidates);
        $judgeMs = (int) ((microtime(true) - $judgeStartedAt) * 1000);

        if ($verdict['decision'] === 'hit' || $verdict['decision'] === 'combine') {
            $this->recordHit($verdict['entries']);

            return new AnswerCacheLookup(
                $verdict['decision'], $verdict['answer'], $verdict['entries'], $elapsed(), $judgeMs,
                $verdict['tokens'], $verdict['cost_credits'],
            );
        }

        return new AnswerCacheLookup(
            $verdict['decision'], lookupMs: $elapsed(), judgeMs: $judgeMs,
            judgeTokens: $verdict['tokens'], judgeCostCredits: $verdict['cost_credits'],
        );
    }

    /**
     * @param  float[]  $vector
     */
    public function store(
        Chatbot $chatbot,
        int $generation,
        string $promptHash,
        string $question,
        array $vector,
        string $answer,
        ?array $sources,
        ?float $confidence,
        ?int $generationTokens,
        ?int $generationCostCredits,
    ): ?ChatbotAnswerCacheEntry {
        $normalized = self::normalize($question);
        $entry = new ChatbotAnswerCacheEntry([
            'team_id' => $chatbot->team_id,
            'chatbot_id' => $chatbot->id,
            'generation' => $generation,
            'prompt_hash' => $promptHash,
            'question' => $normalized,
            'question_hash' => hash('sha256', $normalized),
            'answer' => $answer,
            'sources' => $sources,
            'confidence' => $confidence,
            'generation_tokens' => $generationTokens,
            'generation_cost_credits' => $generationCostCredits,
            'expires_at' => now()->addHours($this->settings($chatbot)['ttl_hours']),
        ]);
        $entry->setAttribute('embedding', $this->isPgsql()
            ? DB::raw("'".$this->pgvectorLiteral($vector)."'::vector")
            : json_encode(array_values($vector)));

        try {
            $entry->save();
        } catch (QueryException $e) {
            // Two concurrent misses on the same question: the first write wins.
            Log::info('ChatbotAnswerCache: entry not stored', ['chatbot_id' => $chatbot->id, 'error' => $e->getMessage()]);

            return null;
        }

        $this->prune($chatbot);

        return $entry;
    }

    /**
     * Knowledge changed: older answers must never be served again.
     */
    public function invalidate(string $chatbotId): void
    {
        Chatbot::withoutGlobalScopes()->whereKey($chatbotId)->increment('answer_cache_generation');
        ChatbotAnswerCacheEntry::withoutGlobalScopes()->where('chatbot_id', $chatbotId)->delete();
    }

    /**
     * Drop expired rows and keep at most max_entries, least recently used first out.
     */
    public function prune(Chatbot $chatbot): void
    {
        ChatbotAnswerCacheEntry::withoutGlobalScopes()
            ->where('team_id', $chatbot->team_id)
            ->where('chatbot_id', $chatbot->id)
            ->where('expires_at', '<=', now())
            ->delete();

        $keepIds = ChatbotAnswerCacheEntry::withoutGlobalScopes()
            ->where('team_id', $chatbot->team_id)
            ->where('chatbot_id', $chatbot->id)
            ->orderByRaw('COALESCE(last_hit_at, created_at) DESC')
            ->limit($this->settings($chatbot)['max_entries'])
            ->pluck('id');

        ChatbotAnswerCacheEntry::withoutGlobalScopes()
            ->where('team_id', $chatbot->team_id)
            ->where('chatbot_id', $chatbot->id)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    /**
     * The only way cache rows are read. Tenant isolation lives here, not in
     * TeamScope: the public widget has no authenticated user (TeamScope filters
     * nothing), and an MCP caller's bound team can differ from the user's UI team.
     *
     * @return Builder<ChatbotAnswerCacheEntry>
     */
    public function scopedQuery(Chatbot $chatbot, int $generation, string $promptHash): Builder
    {
        return ChatbotAnswerCacheEntry::withoutGlobalScopes()
            ->where('team_id', $chatbot->team_id)
            ->where('chatbot_id', $chatbot->id)
            ->where('generation', $generation)
            ->where('prompt_hash', $promptHash)
            ->where('expires_at', '>', now());
    }

    /**
     * Top-N entries by cosine distance, within the prefilter distance.
     *
     * @param  float[]  $vector
     * @return list<ChatbotAnswerCacheEntry>
     */
    public function candidates(Chatbot $chatbot, int $generation, string $promptHash, array $vector): array
    {
        $limit = (int) config('chatbot_answer_cache.candidates', 5);
        $maxDistance = (float) config('chatbot_answer_cache.candidate_max_distance', 0.5);
        $query = $this->scopedQuery($chatbot, $generation, $promptHash)->whereNotNull('embedding');

        if ($this->isPgsql()) {
            $literal = $this->pgvectorLiteral($vector);

            return $query
                ->whereRaw('(embedding <=> ?::vector) <= ?', [$literal, $maxDistance])
                ->orderByRaw('embedding <=> ?::vector', [$literal])
                ->limit($limit)
                ->get()
                ->all();
        }

        // Non-pgsql (SQLite tests): same filters, distance computed in PHP.
        $scored = [];
        foreach ($query->get() as $entry) {
            $distance = $this->cosineDistance($vector, json_decode((string) $entry->getRawOriginal('embedding'), true) ?: []);
            if ($distance <= $maxDistance) {
                $scored[] = [$distance, $entry];
            }
        }
        usort($scored, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return array_map(fn (array $pair) => $pair[1], array_slice($scored, 0, $limit));
    }

    /**
     * Ask a cheap model whether the candidates answer the question. It never
     * sees distances. Any failure or malformed verdict is a reject.
     *
     * @param  list<ChatbotAnswerCacheEntry>  $candidates
     * @return array{decision: string, answer: string|null, entries: list<ChatbotAnswerCacheEntry>, tokens: int, cost_credits: int}
     */
    public function judge(Chatbot $chatbot, string $question, array $candidates): array
    {
        $reject = fn (string $decision = 'reject', int $tokens = 0, int $credits = 0): array => [
            'decision' => $decision, 'answer' => null, 'entries' => [], 'tokens' => $tokens, 'cost_credits' => $credits,
        ];

        $listing = [];
        foreach ($candidates as $i => $entry) {
            $listing[] = '['.($i + 1)."] Question: {$entry->question}\nAnswer: {$entry->answer}";
        }

        try {
            $resolved = $this->providerResolver->resolveInternal(Team::find($chatbot->team_id), 'cheap');
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $resolved['provider'],
                model: $resolved['model'],
                systemPrompt: self::judgeSystemPrompt(),
                userPrompt: "NEW QUESTION:\n{$question}\n\nCANDIDATES:\n".implode("\n\n", $listing),
                maxTokens: 2048,
                teamId: $chatbot->team_id,
                purpose: self::JUDGE_PURPOSE,
                temperature: 0.0,
            ));
        } catch (\Throwable $e) {
            Log::warning('ChatbotAnswerCache: judge call failed, treating as miss', [
                'chatbot_id' => $chatbot->id,
                'error' => $e->getMessage(),
            ]);

            return $reject('error');
        }

        $tokens = $response->usage->totalTokens();
        $credits = $response->usage->costCredits;
        $verdict = StructuredDecisionPrompt::extractFirstJsonObject($response->content) ?? [];
        $decision = $verdict['decision'] ?? null;
        $use = array_values(array_unique(array_map('intval', is_array($verdict['use'] ?? null) ? $verdict['use'] : [])));
        $answer = is_string($verdict['answer'] ?? null) ? trim($verdict['answer']) : '';

        $entries = [];
        foreach ($use as $n) {
            if (! isset($candidates[$n - 1])) {
                return $reject('reject', $tokens, $credits);
            }
            $entries[] = $candidates[$n - 1];
        }

        if ($decision === 'hit' && count($entries) === 1) {
            return [
                'decision' => 'hit',
                'answer' => $answer !== '' ? $answer : $entries[0]->answer,
                'entries' => $entries,
                'tokens' => $tokens,
                'cost_credits' => $credits,
            ];
        }

        if ($decision === 'combine' && count($entries) >= 2 && $answer !== '') {
            return ['decision' => 'combine', 'answer' => $answer, 'entries' => $entries, 'tokens' => $tokens, 'cost_credits' => $credits];
        }

        return $reject('reject', $tokens, $credits);
    }

    /**
     * Store-time screen by a cheap model: personal data, follow-up questions and
     * non-answers. Runs in the queued store job, never on the visitor's request.
     */
    public function passesStoreCheck(Chatbot $chatbot, string $question, string $answer): bool
    {
        try {
            $resolved = $this->providerResolver->resolveInternal(Team::find($chatbot->team_id), 'cheap');
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $resolved['provider'],
                model: $resolved['model'],
                systemPrompt: self::checkSystemPrompt(),
                userPrompt: "QUESTION:\n{$question}\n\nANSWER:\n{$answer}",
                maxTokens: 256,
                teamId: $chatbot->team_id,
                purpose: self::CHECK_PURPOSE,
                temperature: 0.0,
            ));
        } catch (\Throwable $e) {
            Log::warning('ChatbotAnswerCache: store check failed, not storing', [
                'chatbot_id' => $chatbot->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $verdict = StructuredDecisionPrompt::extractFirstJsonObject($response->content) ?? [];

        return ($verdict['store'] ?? false) === true;
    }

    /**
     * Cheap text checks: empty, the chatbot's own fallback, or a stock "I don't know".
     */
    public static function looksLikeNonAnswer(string $answer, ?string $fallbackMessage): bool
    {
        $answer = trim($answer);
        if (mb_strlen($answer) < 2) {
            return true;
        }
        if ($fallbackMessage && str_contains($answer, trim($fallbackMessage))) {
            return true;
        }

        return (bool) preg_match(
            '/\b(i don\'?t know|i do not know|i\'?m not sure|i am not sure|i (?:do not|don\'?t) have (?:that|this|enough|any) information|i (?:can\'?t|cannot) find)\b'
            .'|не знам|не съм сигурн|нямам (?:тази |такава |достатъчно |никаква )?информация|не мога да (?:намеря|отговоря)/iu',
            $answer,
        );
    }

    /**
     * @param  list<ChatbotAnswerCacheEntry>  $entries
     */
    private function recordHit(array $entries): void
    {
        ChatbotAnswerCacheEntry::withoutGlobalScopes()
            ->whereIn('id', array_map(fn ($e) => $e->id, $entries))
            ->update(['hit_count' => DB::raw('hit_count + 1'), 'last_hit_at' => now()]);
    }

    private static function judgeSystemPrompt(): string
    {
        return <<<'TXT'
        You decide whether answers a support chatbot gave earlier can answer a new visitor question.
        You get the NEW QUESTION and numbered CANDIDATES, each an earlier question with the answer that was given.

        Decide:
        - "hit": exactly one candidate asks the same thing as the new question: the same subject AND the same specifics (person or age group, product, plan, variant, place, date, quantity). Rewordings and other languages count as the same question. Put its number in "use".
        - "combine": the new question is fully answered only by putting two or more candidate answers together. Put their numbers in "use".
        - "reject": anything else. A different specific (adults vs children, plan A vs plan B, one city vs another), an answer that covers only part of the question, or any doubt means reject.

        In "answer", write the answer for the new question in the new question's language and wording, using ONLY facts stated in the candidate answers you used. Never add a fact.

        Reply ONLY with JSON: {"decision": "hit" | "combine" | "reject", "use": [numbers], "answer": "string"}
        TXT;
    }

    private static function checkSystemPrompt(): string
    {
        return <<<'TXT'
        You screen a support-chatbot question and its answer before they are saved and reused for OTHER visitors.

        Reply store=false if ANY of these is true:
        - the question or the answer contains personal data about a specific person: a name, e-mail, phone, address, ID, account or order number;
        - the question only makes sense together with earlier messages (for example "and how much is it?", "what about him?");
        - the answer does not really answer: a refusal, "I don't know", a clarifying question, or only "contact support";
        - the answer is about this visitor's own case rather than general information.
        Otherwise reply store=true.

        Reply ONLY with JSON: {"store": true | false, "reason": "short reason"}
        TXT;
    }

    /**
     * @param  float[]  $vector
     */
    private function pgvectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(fn ($v) => (string) (float) $v, $vector)).']';
    }

    /**
     * @param  float[]  $a
     * @param  float[]  $b
     */
    private function cosineDistance(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] ** 2;
            $nb += $b[$i] ** 2;
        }

        return ($na == 0.0 || $nb == 0.0) ? 1.0 : 1.0 - $dot / (sqrt($na) * sqrt($nb));
    }

    private function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
