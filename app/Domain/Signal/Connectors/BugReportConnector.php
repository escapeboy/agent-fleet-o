<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Jobs\EnrichBugReportJob;
use App\Domain\Signal\Models\Signal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class BugReportConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    public function getDriverName(): string
    {
        return 'bug_report';
    }

    /**
     * Ingest a bug report as a signal.
     *
     * Config expects: ['payload' => array, 'files' => array, 'team_id' => string, 'project_key' => string]
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $payload = $config['payload'] ?? [];
        $teamId = $config['team_id'] ?? null;
        $projectKey = $config['project_key'] ?? null;
        $files = $config['files'] ?? [];

        if (empty($payload)) {
            Log::warning('BugReportConnector: Empty payload');

            return [];
        }

        // When breadcrumbs are provided, use them as the canonical action log
        if (! empty($payload['breadcrumbs'])) {
            $breadcrumbs = is_string($payload['breadcrumbs'])
                ? (json_decode($payload['breadcrumbs'], true) ?? [])
                : $payload['breadcrumbs'];

            if (is_array($breadcrumbs)) {
                $payload['action_log'] = $breadcrumbs;
            }
        }

        // Parse JSON-encoded log strings into structured arrays
        foreach (['action_log', 'console_log', 'network_log', 'breadcrumbs', 'failed_responses'] as $field) {
            if (isset($payload[$field]) && is_string($payload[$field])) {
                $decoded = json_decode($payload[$field], true);
                $payload[$field] = is_array($decoded) ? $decoded : [];
            }
        }

        $severity = $payload['severity'] ?? 'minor';
        $tags = ['bug_report', $severity];

        if ($projectKey) {
            $tags[] = 'project:'.$projectKey;
        }

        $signal = $this->ingestAction->execute(
            sourceType: 'bug_report',
            sourceIdentifier: $projectKey ?? ($payload['reporter_id'] ?? 'unknown'),
            payload: $payload,
            tags: $tags,
            teamId: $teamId,
        );

        if ($signal && $projectKey) {
            $signal->update(['project_key' => $projectKey, 'status' => SignalStatus::Received->value]);
        }

        // Store uploaded files to the bug_report_files media collection (not the
        // generic 'attachments' collection), so screenshot and detail views resolve correctly.
        if ($signal) {
            foreach ($files as $file) {
                if ($file instanceof UploadedFile && $file->isValid()) {
                    $signal->addMedia($file)->toMediaCollection('bug_report_files');
                }
            }
        }

        if ($signal) {
            EnrichBugReportJob::dispatch($signal->id);
        }

        return $signal ? [$signal] : [];
    }

    public function supports(string $driver): bool
    {
        return $driver === 'bug_report';
    }
}
