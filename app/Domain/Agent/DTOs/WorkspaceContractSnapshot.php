<?php

namespace App\Domain\Agent\DTOs;

/**
 * Materialized workspace contract for a single agent execution.
 *
 * Each field is the literal text content that gets written to the
 * sandbox root as a file (AGENTS.md, feature-list.json, progress.md, init.sh).
 *
 * The snapshot is also persisted to AgentExecution.workspace_contract JSONB
 * so it can be replayed on the next sandbox boot for the same execution.
 */
final readonly class WorkspaceContractSnapshot
{
    public function __construct(
        public string $agentsMd,
        public string $featureListJson,
        public string $progressMd,
        public string $initSh,
    ) {}

    /**
     * @return array{agents_md: string, feature_list_json: string, progress_md: string, init_sh: string}
     */
    public function toArray(): array
    {
        return [
            'agents_md' => $this->agentsMd,
            'feature_list_json' => $this->featureListJson,
            'progress_md' => $this->progressMd,
            'init_sh' => $this->initSh,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            agentsMd: (string) ($payload['agents_md'] ?? ''),
            featureListJson: (string) ($payload['feature_list_json'] ?? '{}'),
            progressMd: (string) ($payload['progress_md'] ?? ''),
            initSh: (string) ($payload['init_sh'] ?? "#!/usr/bin/env bash\nset -euo pipefail\n"),
        );
    }
}
