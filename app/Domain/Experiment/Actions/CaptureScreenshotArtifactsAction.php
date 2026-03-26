<?php

namespace App\Domain\Experiment\Actions;

use App\Models\Artifact;
use App\Models\ArtifactVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CaptureScreenshotArtifactsAction
{
    /** Base64 size guard (~200 KB encoded). */
    private const MAX_SCREENSHOT_BYTES = 200_000;

    /**
     * Create Artifact + ArtifactVersion records from browser tool screenshots.
     *
     * Each entry in $screenshots must contain at least a 'base64' key (PNG data)
     * and optionally a 'label' key.  Failures are logged and swallowed — a capture
     * error must never propagate and break the enclosing tool call.
     *
     * @param  array<int, array{base64?: string, label?: string}>  $screenshots
     */
    public function execute(
        array $screenshots,
        string $teamId,
        ?string $experimentId,
        ?string $agentId = null,
        int $stepIndex = 1,
    ): void {
        foreach ($screenshots as $index => $screenshot) {
            $base64 = $screenshot['base64'] ?? null;

            if (! $base64) {
                continue;
            }

            // Reject oversized payloads before any decoding attempt.
            if (strlen($base64) > self::MAX_SCREENSHOT_BYTES) {
                $content = '[screenshot truncated — exceeds 200 KB limit]';
            } else {
                // Validate the decoded bytes start with the PNG magic signature (\x89PNG)
                // to prevent storing crafted content (e.g. SVG/HTML) that could cause
                // stored XSS when served via the artifact preview controller.
                $decoded = base64_decode($base64, strict: true);

                if ($decoded === false || ! str_starts_with($decoded, "\x89PNG")) {
                    Log::warning('CaptureScreenshotArtifactsAction: rejected non-PNG screenshot payload', [
                        'team_id' => $teamId,
                        'step_index' => $stepIndex,
                        'screenshot_index' => $index,
                    ]);

                    continue;
                }

                $content = 'data:image/png;base64,'.$base64;
            }

            $label = $screenshot['label'] ?? "Screenshot {$stepIndex}.{$index}";

            try {
                DB::transaction(function () use ($content, $label, $teamId, $experimentId, $agentId, $stepIndex, $index): void {
                    $artifact = Artifact::withoutGlobalScopes()->create([
                        'team_id' => $teamId,
                        'experiment_id' => $experimentId,
                        'type' => 'screenshot',
                        'name' => $label,
                        'current_version' => 1,
                        'metadata' => [
                            'source' => 'browser_tool',
                            'agent_id' => $agentId,
                            'step_index' => $stepIndex,
                            'screenshot_index' => $index,
                            'mime' => 'image/png',
                        ],
                    ]);

                    ArtifactVersion::withoutGlobalScopes()->create([
                        'artifact_id' => $artifact->id,
                        'version' => 1,
                        'content' => $content,
                        'metadata' => ['captured_at' => now()->toISOString()],
                    ]);
                });
            } catch (\Throwable $e) {
                Log::warning('CaptureScreenshotArtifactsAction: failed to save screenshot artifact', [
                    'team_id' => $teamId,
                    'experiment_id' => $experimentId,
                    'step_index' => $stepIndex,
                    'screenshot_index' => $index,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
