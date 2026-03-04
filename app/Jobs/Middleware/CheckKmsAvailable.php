<?php

namespace App\Jobs\Middleware;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\TeamKmsConfig;
use App\Domain\Shared\Services\NotificationService;
use App\Infrastructure\Encryption\Kms\Exceptions\KmsUnavailableException;
use Closure;
use Illuminate\Support\Facades\Log;

class CheckKmsAvailable
{
    public function handle(object $job, Closure $next): void
    {
        $teamId = $this->resolveTeamId($job);

        if (! $teamId) {
            $next($job);

            return;
        }

        // Check if team has KMS in error state before even running the job
        $kmsConfig = TeamKmsConfig::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->first();

        if ($kmsConfig && $kmsConfig->status->value === 'error') {
            Log::warning('CheckKmsAvailable: KMS in error state, skipping job', [
                'team_id' => $teamId,
                'provider' => $kmsConfig->provider->value,
                'job' => class_basename($job),
            ]);

            // Don't silently drop — release back to retry later
            if (method_exists($job, 'release')) {
                $job->release(60); // retry after 1 minute
            }

            return;
        }

        try {
            $next($job);
        } catch (KmsUnavailableException $e) {
            Log::error('CheckKmsAvailable: KMS became unavailable during job execution', [
                'team_id' => $teamId,
                'provider' => $e->provider ?? 'unknown',
                'reason' => $e->getMessage(),
                'job' => class_basename($job),
            ]);

            $this->notifyTeamAdmins($teamId, $e->getMessage());

            if (method_exists($job, 'release')) {
                $job->release(120); // retry after 2 minutes
            }
        }
    }

    private function resolveTeamId(object $job): ?string
    {
        if (property_exists($job, 'teamId')) {
            return $job->teamId;
        }

        if (property_exists($job, 'experimentId')) {
            return Experiment::withoutGlobalScopes()
                ->where('id', $job->experimentId)
                ->value('team_id');
        }

        return null;
    }

    private function notifyTeamAdmins(string $teamId, string $reason): void
    {
        try {
            app(NotificationService::class)->notifyTeam(
                teamId: $teamId,
                type: 'kms_error',
                title: 'KMS Encryption Error',
                body: "Your KMS provider is unreachable. Credential operations are paused. Check your KMS configuration in Team Settings. Reason: {$reason}",
                actionUrl: '/team?tab=security',
            );
        } catch (\Throwable) {
            // Never let notification failure break the middleware
        }
    }
}
