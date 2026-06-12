<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-corpus contradiction scan — RoBrain's "Synthesis" pass.
 *
 * The per-write dedup gate (StoreMemoryAction) only ever compares a new fact
 * against its nearest neighbour in isolation. It cannot see a contradiction
 * that emerges months later, across sessions: "use Bun as the runtime"
 * (February) vs "migrated back to Node — Bun missing packages" (May).
 *
 * This batch job pairs semantically-similar beliefs, asks an LLM which pairs
 * directly reverse each other, and flags both rows for human review. One LLM
 * call per team per run keeps it cost-bounded.
 */
class DetectMemoryContradictionsAction
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You compare pairs of stored team beliefs and decide which pairs directly contradict.

A pair CONTRADICTS when one statement reverses, negates, or is mutually exclusive
with the other — e.g. "use X" vs "never use X", "X is the runtime" vs "migrated
off X", "deploy on Fridays" vs "no Friday deploys".

A pair does NOT contradict when the statements are merely related, cover different
topics, or are simply two separate facts that can both be true at once.

Return ONLY valid JSON (no markdown fences):
{"contradictions": [1, 4]}

The numbers are the pair numbers you judge to be direct contradictions. Return an
empty array when none contradict.
PROMPT;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * Scan one team (or every team when $teamId is null).
     *
     * @return array{teams_scanned: int, pairs_evaluated: int, contradictions_found: int}
     */
    public function execute(?string $teamId = null): array
    {
        $teamIds = $teamId !== null
            ? [$teamId]
            : Memory::withoutGlobalScopes()->distinct()->pluck('team_id')->filter()->all();

        $pairsEvaluated = 0;
        $found = 0;

        foreach ($teamIds as $tid) {
            $pairs = $this->findCandidatePairs((string) $tid);
            if ($pairs === []) {
                continue;
            }

            $pairsEvaluated += count($pairs);
            $found += $this->scanPairs($pairs, (string) $tid);
        }

        return [
            'teams_scanned' => count($teamIds),
            'pairs_evaluated' => $pairsEvaluated,
            'contradictions_found' => $found,
        ];
    }

    /**
     * Judge a set of already-formed candidate pairs and flag the contradictions.
     * Split out from execute() so it is testable without a pgvector backend.
     *
     * @param  array<int, array{0: Memory, 1: Memory}>  $pairs
     */
    public function scanPairs(array $pairs, string $teamId): int
    {
        if ($pairs === []) {
            return 0;
        }

        $contradicting = $this->judgeContradictions($pairs, $teamId);
        $flagged = 0;

        foreach ($contradicting as $index) {
            if (! isset($pairs[$index])) {
                continue;
            }
            $this->flagPair($pairs[$index][0], $pairs[$index][1]);
            $flagged++;
        }

        return $flagged;
    }

    /**
     * Pair semantically-similar, not-yet-flagged beliefs for one team using
     * pgvector cosine distance. Returns nothing on a non-pgsql connection.
     *
     * @return array<int, array{0: Memory, 1: Memory}>
     */
    private function findCandidatePairs(string $teamId): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }
        if (! Schema::hasColumn('memories', 'embedding')) {
            return [];
        }

        $minSimilarity = (float) config('memory.contradiction_scan.min_similarity', 0.55);
        $maxSimilarity = (float) config('memory.contradiction_scan.max_similarity', 0.92);
        $candidateLimit = (int) config('memory.contradiction_scan.candidate_limit', 60);
        $maxPairs = (int) config('memory.contradiction_scan.max_pairs', 25);

        $candidates = Memory::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('conflict_flag', false)
            ->whereNotNull('embedding')
            ->where(fn ($q) => $q->whereNull('belief_status')->orWhere('belief_status', '!=', 'superseded'))
            ->where(fn ($q) => $q->whereNull('proposal_status')->orWhere('proposal_status', '!=', 'rejected'))
            ->orderByDesc('importance')
            ->orderByDesc('created_at')
            ->limit($candidateLimit)
            ->get();

        $pairs = [];
        $seen = [];

        foreach ($candidates as $memory) {
            $neighbor = Memory::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('id', '!=', $memory->id)
                ->where('conflict_flag', false)
                ->whereNotNull('embedding')
                ->where(fn ($q) => $q->whereNull('belief_status')->orWhere('belief_status', '!=', 'superseded'))
                ->where(fn ($q) => $q->whereNull('proposal_status')->orWhere('proposal_status', '!=', 'rejected'))
                ->selectRaw('memories.*, 1 - (embedding <=> ?) AS similarity', [$memory->embedding])
                ->orderByRaw('embedding <=> ?', [$memory->embedding])
                ->limit(1)
                ->first();

            if (! $neighbor) {
                continue;
            }

            $similarity = (float) $neighbor->similarity;
            if ($similarity < $minSimilarity || $similarity > $maxSimilarity) {
                continue;
            }

            // Unordered pair key — dedup A/B vs B/A.
            $key = $memory->id < $neighbor->id
                ? $memory->id.'|'.$neighbor->id
                : $neighbor->id.'|'.$memory->id;

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pairs[] = [$memory, $neighbor];

            if (count($pairs) >= $maxPairs) {
                break;
            }
        }

        return $pairs;
    }

    /**
     * Ask the LLM which numbered pairs directly contradict each other.
     *
     * @param  array<int, array{0: Memory, 1: Memory}>  $pairs
     * @return array<int, int> Zero-based indexes into $pairs.
     */
    private function judgeContradictions(array $pairs, string $teamId): array
    {
        $lines = [];
        foreach ($pairs as $i => [$a, $b]) {
            $number = $i + 1;
            $lines[] = "Pair {$number}:\nA: {$a->content}\nB: {$b->content}";
        }
        $userPrompt = implode("\n\n", $lines);

        try {
            $team = Team::find($teamId);
            $resolved = $this->providerResolver->resolve(team: $team);

            // The model carries its own provider prefix ("anthropic/claude-haiku-4-5")
            // so the model name is never paired with a foreign resolved provider
            // (which 400s on gateways that don't expose Anthropic models). An
            // un-prefixed override falls back to the team-resolved provider.
            $configured = (string) config('memory.contradiction_scan.model', 'anthropic/claude-haiku-4-5');
            [$provider, $model] = str_contains($configured, '/')
                ? explode('/', $configured, 2)
                : [$resolved['provider'], $configured];

            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $provider,
                model: $model,
                systemPrompt: self::SYSTEM_PROMPT,
                userPrompt: $userPrompt,
                maxTokens: 256,
                userId: Team::ownerIdFor($teamId),
                teamId: $teamId,
                purpose: 'memory.contradiction_scan',
                temperature: 0.0,
            ));

            $content = trim($response->content);
            if (str_starts_with($content, '```')) {
                $content = preg_replace('/^```(?:json)?\s*\n?/', '', $content);
                $content = preg_replace('/\n?```\s*$/', '', (string) $content);
            }

            $decoded = json_decode((string) $content, true);
            if (! is_array($decoded) || ! isset($decoded['contradictions']) || ! is_array($decoded['contradictions'])) {
                return [];
            }

            // LLM speaks 1-based pair numbers; convert to 0-based indexes.
            return array_values(array_filter(array_map(
                fn ($n) => is_numeric($n) ? (int) $n - 1 : -1,
                $decoded['contradictions'],
            ), fn ($i) => $i >= 0));
        } catch (\Throwable $e) {
            Log::warning('DetectMemoryContradictionsAction: judging failed', [
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Flag both memories of a contradicting pair, each pointing at the other.
     */
    private function flagPair(Memory $a, Memory $b): void
    {
        $now = now();

        foreach ([[$a, $b], [$b, $a]] as [$memory, $other]) {
            Memory::withoutGlobalScopes()
                ->where('id', $memory->id)
                ->update([
                    'conflict_flag' => true,
                    'conflict_with_id' => $other->id,
                    'conflict_detected_at' => $now,
                ]);
        }
    }
}
