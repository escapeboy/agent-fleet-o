<?php

namespace App\Livewire\Admin;

use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Scopes\TeamScope;
use App\Domain\Signal\Jobs\RunSentryWatchdogJob;
use App\Domain\Signal\Models\SentryWatchdogRun;
use App\Domain\Signal\Models\Signal;
use Livewire\Attributes\Url;
use Livewire\Component;

class SentryWatchdogPage extends Component
{
    #[Url]
    public ?string $selectedRunId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);
    }

    public function selectRun(string $runId): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        $this->selectedRunId = $runId;
    }

    public function runNow(): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        $integrations = Integration::withoutGlobalScopes()
            ->where('driver', 'sentry')
            ->where('status', IntegrationStatus::Active)
            ->get()
            ->filter(fn (Integration $integration) => (bool) ($integration->config['watchdog_enabled'] ?? false));

        foreach ($integrations as $integration) {
            RunSentryWatchdogJob::dispatch($integration->id);
        }

        session()->flash(
            'status',
            $integrations->isEmpty()
                ? 'No enabled Sentry integrations found to run.'
                : 'Watchdog run dispatched ('.$integrations->count().' integration'.($integrations->count() === 1 ? '' : 's').'). Refresh in a minute to see results.',
        );
    }

    public function render()
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        $runs = SentryWatchdogRun::withoutGlobalScopes()
            ->with([
                'integration' => fn ($q) => $q->withoutGlobalScope(TeamScope::class),
                'team' => fn ($q) => $q->withoutGlobalScope(TeamScope::class),
            ])
            ->orderByDesc('started_at')
            ->limit(30)
            ->get();

        $totalRuns30d = SentryWatchdogRun::withoutGlobalScopes()
            ->where('started_at', '>=', now()->subDays(30))
            ->count();

        $totalCritical30d = (int) SentryWatchdogRun::withoutGlobalScopes()
            ->where('started_at', '>=', now()->subDays(30))
            ->sum('critical_count');

        $lastRunAt = $runs->first()?->started_at;

        $selectedRun = null;
        $relatedSignals = collect();

        if ($this->selectedRunId !== null) {
            $selectedRun = SentryWatchdogRun::withoutGlobalScopes()
                ->with([
                    'integration' => fn ($q) => $q->withoutGlobalScope(TeamScope::class),
                    'team' => fn ($q) => $q->withoutGlobalScope(TeamScope::class),
                ])
                ->find($this->selectedRunId);

            if ($selectedRun !== null) {
                $windowEnd = $selectedRun->finished_at ?? now();

                $relatedSignals = Signal::withoutGlobalScopes()
                    ->where('source_identifier', 'sentry')
                    ->where('team_id', $selectedRun->team_id)
                    ->whereBetween('updated_at', [$selectedRun->started_at, $windowEnd])
                    ->orderByDesc('updated_at')
                    ->limit(50)
                    ->get();
            }
        }

        return view('livewire.admin.sentry-watchdog-page', [
            'runs' => $runs,
            'totalRuns30d' => $totalRuns30d,
            'totalCritical30d' => $totalCritical30d,
            'lastRunAt' => $lastRunAt,
            'selectedRun' => $selectedRun,
            'relatedSignals' => $relatedSignals,
            'mode' => (string) config('sentry_watchdog.mode'),
        ])->layout('layouts.app', ['header' => 'Sentry Watchdog']);
    }
}
