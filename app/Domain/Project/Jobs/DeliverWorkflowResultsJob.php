<?php

namespace App\Domain\Project\Jobs;

use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Outbound\Actions\SendOutboundAction;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Project\Models\ProjectRun;
use App\Models\Artifact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DeliverWorkflowResultsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly string $projectRunId,
    ) {
        $this->onQueue('outbound');
    }

    public function handle(SendOutboundAction $sendAction): void
    {
        $run = ProjectRun::withoutGlobalScopes()
            ->with(['project', 'experiment'])
            ->find($this->projectRunId);

        if (! $run || ! $run->project || ! $run->experiment) {
            Log::warning('DeliverWorkflowResultsJob: Run or related models not found', [
                'run_id' => $this->projectRunId,
            ]);

            return;
        }

        $project = $run->project;
        $experiment = $run->experiment;
        $deliveryConfig = $project->delivery_config;

        if (empty($deliveryConfig) || ($deliveryConfig['channel'] ?? 'none') === 'none') {
            return;
        }

        // Collect outputs from completed playbook steps
        $summary = $this->collectWorkflowOutput($experiment->id);

        // Store summary on the run
        $run->update(['output_summary' => $summary]);

        // Build and send via outbound connector
        $channel = $deliveryConfig['channel'];
        $target = $this->buildTarget($channel, $deliveryConfig);
        $content = $this->buildContent($project, $run, $summary, $deliveryConfig);

        try {
            $proposal = OutboundProposal::withoutGlobalScopes()->create([
                'team_id' => $project->team_id,
                'experiment_id' => $experiment->id,
                'channel' => $channel,
                'target' => $target,
                'content' => $content,
                'risk_score' => 0,
                'status' => OutboundProposalStatus::Approved,
            ]);

            $sendAction->execute($proposal);

            Log::info('DeliverWorkflowResultsJob: Results delivered', [
                'run_id' => $run->id,
                'channel' => $channel,
                'project_id' => $project->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('DeliverWorkflowResultsJob: Delivery failed', [
                'run_id' => $run->id,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function collectWorkflowOutput(string $experimentId): string
    {
        $steps = PlaybookStep::where('experiment_id', $experimentId)
            ->where('status', 'completed')
            ->orderBy('order')
            ->get();

        if ($steps->isEmpty()) {
            return 'Workflow completed with no output.';
        }

        $parts = [];
        foreach ($steps as $step) {
            $output = $step->output;
            if (! $output) {
                continue;
            }

            $label = $step->label ?? $step->skill_name ?? "Step {$step->order}";

            if (is_array($output)) {
                $text = $output['result'] ?? $output['text'] ?? $output['content'] ?? json_encode($output, JSON_PRETTY_PRINT);
            } else {
                $text = (string) $output;
            }

            $parts[] = "## {$label}\n\n{$text}";
        }

        return implode("\n\n---\n\n", $parts) ?: 'Workflow completed with no output.';
    }

    private function buildTarget(string $channel, array $config): array
    {
        return match ($channel) {
            'email' => ['email' => $config['target'] ?? ''],
            'slack' => [
                'webhook_url' => $config['webhook_url'] ?? config('services.slack.webhook_url'),
                'channel' => $config['target'] ?? null,
            ],
            'telegram' => ['chat_id' => $config['target'] ?? ''],
            'webhook' => ['url' => $config['target'] ?? ''],
            default => [],
        };
    }

    private function buildContent(mixed $project, ProjectRun $run, string $summary, array $config): array
    {
        $format = $config['format'] ?? 'summary';
        $title = "{$project->title} — Run #{$run->run_number}";

        $body = match ($format) {
            'full' => $summary,
            'json' => $summary,
            default => $this->truncateSummary($summary, 2000),
        };

        // Append artifact info if any exist
        $artifactSection = $this->buildArtifactSection($run);
        if ($artifactSection) {
            $body .= "\n\n---\n\n".$artifactSection;
        }

        return [
            'subject' => $title,
            'body' => $body,
            'text' => $body,
        ];
    }

    private function buildArtifactSection(ProjectRun $run): ?string
    {
        $artifacts = Artifact::withoutGlobalScopes()
            ->where('experiment_id', $run->experiment_id)
            ->orderBy('created_at')
            ->get();

        if ($artifacts->isEmpty()) {
            return null;
        }

        $lines = ["**Artifacts ({$artifacts->count()}):**"];

        foreach ($artifacts as $artifact) {
            $previewUrl = url("/artifacts/{$artifact->id}/render");
            $lines[] = "- [{$artifact->name}]({$previewUrl}) ({$artifact->type})";
        }

        return implode("\n", $lines);
    }

    private function truncateSummary(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3).'...';
    }
}
