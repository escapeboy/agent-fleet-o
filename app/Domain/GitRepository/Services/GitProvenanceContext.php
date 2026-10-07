<?php

namespace App\Domain\GitRepository\Services;

/**
 * Mutable per-job/request holder describing who is making a commit.
 * Bound with app()->scoped() so it is flushed between queue jobs.
 */
class GitProvenanceContext
{
    public ?string $teamId = null;

    public ?string $experimentId = null;

    public ?string $agentId = null;

    public ?string $skillExecutionId = null;

    public string $source = 'other';

    private const FIELDS = [
        'teamId' => 'team_id',
        'experimentId' => 'experiment_id',
        'agentId' => 'agent_id',
        'skillExecutionId' => 'skill_execution_id',
        'source' => 'source',
    ];

    /**
     * Run $fn with the given context fields (keys: team_id, experiment_id,
     * agent_id, skill_execution_id, source) and restore the previous values.
     * Fields not present in $ctx are reset to their defaults for the duration.
     *
     * @param  array<string, string|null>  $ctx
     */
    public function with(array $ctx, callable $fn): mixed
    {
        $previous = $this->toArray();

        foreach (self::FIELDS as $prop => $key) {
            $this->{$prop} = $prop === 'source'
                ? (isset($ctx[$key]) ? (string) $ctx[$key] : 'other')
                : ($ctx[$key] ?? null);
        }

        try {
            return $fn();
        } finally {
            foreach (self::FIELDS as $prop => $key) {
                $this->{$prop} = $previous[$key];
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function trailers(): array
    {
        return array_filter([
            'FleetQ-Experiment' => $this->experimentId,
            'FleetQ-Agent' => $this->agentId,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @return array{team_id: ?string, experiment_id: ?string, agent_id: ?string, skill_execution_id: ?string, source: string}
     */
    public function toArray(): array
    {
        return [
            'team_id' => $this->teamId,
            'experiment_id' => $this->experimentId,
            'agent_id' => $this->agentId,
            'skill_execution_id' => $this->skillExecutionId,
            'source' => $this->source,
        ];
    }
}
