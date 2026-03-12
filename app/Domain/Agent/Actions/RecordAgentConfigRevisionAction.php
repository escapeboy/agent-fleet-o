<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentConfigRevision;

class RecordAgentConfigRevisionAction
{
    /**
     * Fields tracked for config revisions. Changes to other fields (e.g. budget_spent_credits,
     * risk_score) are not considered meaningful "config" changes.
     */
    private const TRACKED_KEYS = [
        'name', 'role', 'goal', 'backstory', 'personality',
        'provider', 'model', 'config', 'capabilities', 'constraints',
        'budget_cap_credits', 'evaluation_enabled', 'evaluation_model', 'evaluation_criteria',
    ];

    /**
     * Record a config revision for an agent.
     *
     * Call this BEFORE the agent is updated, passing the new data array.
     * The action snapshots the current state, then computes the diff.
     *
     * @param  array<string, mixed>  $newData  The data being applied to the agent
     * @return AgentConfigRevision|null  null if no tracked fields changed
     */
    public function execute(
        Agent $agent,
        array $newData,
        string $source = 'manual',
        ?string $userId = null,
        ?string $rolledBackFromRevisionId = null,
        ?string $notes = null,
    ): ?AgentConfigRevision {
        $beforeConfig = $this->snapshot($agent);

        // Compute what will change in tracked fields
        $changedKeys = [];
        foreach (self::TRACKED_KEYS as $key) {
            if (! array_key_exists($key, $newData)) {
                continue;
            }
            $old = $beforeConfig[$key] ?? null;
            $new = $newData[$key];
            if ($old !== $new) {
                $changedKeys[] = $key;
            }
        }

        if (empty($changedKeys)) {
            return null;
        }

        // Build after_config by merging new data over the snapshot
        $afterConfig = array_merge(
            $beforeConfig,
            array_intersect_key($newData, array_flip(self::TRACKED_KEYS)),
        );

        return AgentConfigRevision::create([
            'agent_id' => $agent->id,
            'team_id' => $agent->team_id,
            'created_by' => $userId,
            'before_config' => $beforeConfig,
            'after_config' => $afterConfig,
            'changed_keys' => $changedKeys,
            'source' => $source,
            'rolled_back_from_revision_id' => $rolledBackFromRevisionId,
            'notes' => $notes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Agent $agent): array
    {
        $snapshot = [];
        foreach (self::TRACKED_KEYS as $key) {
            $snapshot[$key] = $agent->getAttribute($key);
        }

        return $snapshot;
    }
}
