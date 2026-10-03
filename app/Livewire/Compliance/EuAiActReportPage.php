<?php

namespace App\Livewire\Compliance;

use App\Domain\Audit\Services\EuAiActReportBuilder;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

class EuAiActReportPage extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->ensureAllowed();
    }

    public function render()
    {
        // Re-checked on every request: mount() does not run on Livewire updates.
        $this->ensureAllowed();

        $report = null;
        $periodError = null;

        try {
            [$from, $to] = EuAiActReportBuilder::resolvePeriod($this->from ?: null, $this->to ?: null);
            $report = app(EuAiActReportBuilder::class)->build((string) auth()->user()->current_team_id, $from, $to);
        } catch (InvalidArgumentException $e) {
            $periodError = $e->getMessage();
        }

        return view('livewire.compliance.eu-ai-act-report-page', [
            'report' => $report,
            'periodError' => $periodError,
        ])->layout('layouts.app', ['header' => 'EU AI Act Report']);
    }

    private function ensureAllowed(): void
    {
        abort_unless(config('audit.compliance_report.enabled'), 404);
        // Security posture of the team — same audience as the audit log.
        abort_unless(auth()->check() && Gate::allows('manage-team'), 403);
    }
}
