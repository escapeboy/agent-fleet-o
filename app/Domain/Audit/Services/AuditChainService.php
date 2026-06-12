<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Asynchronous "notary" that links audit_entries into per-team SHA-256 hash
 * chains, making later mutation or deletion of chained rows detectable.
 *
 * Chain order is the UUIDv7 primary key (generation-time ordered) rather than
 * created_at, which is caller-supplied and may collide. Rows younger than the
 * settle window are skipped so in-flight transactions can commit before their
 * id range is sealed. Rows with team_id NULL form the 'platform' chain.
 *
 * Known limit: an attacker with unrestricted DB write access could rewrite
 * the chain from the tamper point forward; external anchoring (signed
 * checkpoints) is a documented follow-up, not part of this iteration.
 */
class AuditChainService
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Link pending (unchained) entries for every chain group.
     *
     * @return array<string, int> chained-row count per group key
     */
    public function chainPending(?int $batchSize = null): array
    {
        $batchSize = $batchSize ?? (int) config('audit.hash_chain.batch_size', 1000);
        $settleSeconds = (int) config('audit.hash_chain.settle_seconds', 120);
        $settledBefore = now()->subSeconds($settleSeconds);

        $counts = [];

        foreach ($this->chainGroups() as $teamId) {
            // withoutOverlapping/onOneServer on the schedule are best-effort;
            // an atomic per-group lock is what actually prevents two writers
            // from extending the same chain with conflicting hashes.
            $lock = Cache::lock('audit:chain:'.$this->groupKey($teamId), 600);

            if (! $lock->get()) {
                continue;
            }

            try {
                $cursor = $this->lastChainedEntry($teamId);
                $prevHash = $cursor->entry_hash ?? self::GENESIS_HASH;

                $pending = $this->groupQuery($teamId)
                    ->whereNull('entry_hash')
                    ->when($cursor, fn ($q) => $q->where('id', '>', $cursor->id))
                    ->where('created_at', '<=', $settledBefore)
                    ->orderBy('id')
                    ->limit($batchSize)
                    ->get();

                foreach ($pending as $entry) {
                    $entryHash = $this->computeHash($entry, $prevHash);

                    DB::table('audit_entries')->where('id', $entry->id)->update([
                        'prev_hash' => $prevHash,
                        'entry_hash' => $entryHash,
                    ]);

                    $prevHash = $entryHash;
                }

                if ($pending->isNotEmpty()) {
                    $counts[$this->groupKey($teamId)] = $pending->count();
                }
            } finally {
                $lock->release();
            }
        }

        return $counts;
    }

    /**
     * Recompute and validate the chain for one team or every group.
     *
     * The oldest retained chained row acts as anchor: its prev_hash is not
     * checked against a predecessor, because audit:cleanup deletes oldest
     * rows first and would otherwise produce a permanent false break.
     *
     * 'checked' counts entries that verified OK; on a broken chain the
     * breaking entry itself is reported in first_break_id, not in checked.
     *
     * @return array<int, array{group: string, checked: int, status: string, first_break_id: string|null, unchained_stragglers: int}>
     */
    public function verifyChain(?string $teamId = null): array
    {
        $groups = $teamId !== null ? [$teamId] : $this->chainGroups(chainedOnly: true);
        $reports = [];

        foreach ($groups as $groupTeamId) {
            $checked = 0;
            $breakId = null;
            $expectedPrev = null;

            $this->groupQuery($groupTeamId)
                ->whereNotNull('entry_hash')
                ->orderBy('id')
                ->chunk(500, function ($entries) use (&$checked, &$breakId, &$expectedPrev) {
                    foreach ($entries as $entry) {
                        // Linkage: every row after the anchor must reference its predecessor.
                        if ($expectedPrev !== null && $entry->prev_hash !== $expectedPrev) {
                            $breakId = $entry->id;

                            return false;
                        }

                        // Integrity: the stored hash must match the row's current content.
                        if ($this->computeHash($entry, $entry->prev_hash) !== $entry->entry_hash) {
                            $breakId = $entry->id;

                            return false;
                        }

                        $expectedPrev = $entry->entry_hash;
                        $checked++;
                    }

                    return true;
                });

            $lastChained = $this->lastChainedEntry($groupTeamId);
            $stragglers = $lastChained
                ? $this->groupQuery($groupTeamId)
                    ->whereNull('entry_hash')
                    ->where('id', '<', $lastChained->id)
                    ->count()
                : 0;

            $reports[] = [
                'group' => $this->groupKey($groupTeamId),
                'checked' => $checked,
                'status' => $breakId === null ? 'ok' : 'broken',
                'first_break_id' => $breakId,
                'unchained_stragglers' => $stragglers,
            ];
        }

        return $reports;
    }

    /**
     * Deterministic content hash: canonical payload concatenated with the
     * predecessor hash. Field order is fixed and array values are
     * recursively key-sorted so logically identical payloads always hash
     * identically regardless of JSONB key ordering.
     */
    public function computeHash(AuditEntry $entry, string $prevHash): string
    {
        // team_id arrives via the BelongsToTeam trait migration and created_at
        // is cast at runtime — larastan cannot see either statically, so both
        // go through getAttribute().
        $createdAt = $entry->getAttribute('created_at');

        $payload = [
            'id' => $entry->id,
            'team_id' => $entry->getAttribute('team_id'),
            'user_id' => $entry->user_id,
            'event' => $entry->event,
            'subject_type' => $entry->subject_type,
            'subject_id' => $entry->subject_id,
            'ip_address' => $entry->ip_address,
            'created_at' => $createdAt instanceof \DateTimeInterface ? $createdAt->format(DATE_ATOM) : $createdAt,
            'ocsf_class_uid' => $entry->ocsf_class_uid,
            'ocsf_severity_id' => $entry->ocsf_severity_id,
            'decision_context' => $this->canonicalize($entry->decision_context),
            'triggered_by' => $entry->triggered_by,
            'impersonator_id' => $entry->impersonator_id,
            'properties' => $this->canonicalize($entry->properties),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'|'.$prevHash);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $value = array_map(fn ($v) => $this->canonicalize($v), $value);

        if (! $isList) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @return array<int, string|null> distinct team_id values (null = platform chain)
     */
    private function chainGroups(bool $chainedOnly = false): array
    {
        return AuditEntry::withoutGlobalScopes()
            ->when($chainedOnly, fn ($q) => $q->whereNotNull('entry_hash'))
            ->distinct()
            ->pluck('team_id')
            ->all();
    }

    private function groupQuery(?string $teamId)
    {
        return AuditEntry::withoutGlobalScopes()
            ->when($teamId === null, fn ($q) => $q->whereNull('team_id'), fn ($q) => $q->where('team_id', $teamId));
    }

    private function lastChainedEntry(?string $teamId): ?AuditEntry
    {
        return $this->groupQuery($teamId)
            ->whereNotNull('entry_hash')
            ->orderByDesc('id')
            ->first();
    }

    private function groupKey(?string $teamId): string
    {
        return $teamId ?? 'platform';
    }
}
