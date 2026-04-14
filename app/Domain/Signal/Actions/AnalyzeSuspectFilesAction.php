<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Services\SuspectFilesAnalyzer;

class AnalyzeSuspectFilesAction
{
    public function __construct(
        private readonly SuspectFilesAnalyzer $analyzer,
    ) {}

    /**
     * Compute suspect files and route hints, write back to signal payload.
     */
    public function execute(Signal $signal): void
    {
        $payload = $signal->payload ?? [];

        $result = $this->analyzer->analyze(
            payload: $payload,
            teamId: $signal->team_id,
            projectKey: $signal->project_key,
        );

        if (! empty($result['suspect_files'])) {
            $payload['suspect_files'] = $result['suspect_files'];
        }

        if (! empty($result['source_hints'])) {
            $payload['source_hints'] = $result['source_hints'];
        }

        if (! empty($result['suspect_files']) || ! empty($result['source_hints'])) {
            $signal->update(['payload' => $payload]);
        }
    }
}
