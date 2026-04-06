<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Enums\ExperimentTaskStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentTask;
use App\Domain\Website\Actions\CreateWebsitePageAction;
use App\Domain\Website\Actions\PublishWebsitePageAction;
use App\Domain\Website\Models\Website;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Jobs\Middleware\CheckBudgetAvailable;
use App\Jobs\Middleware\CheckKillSwitch;
use App\Jobs\Middleware\TenantRateLimit;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generates HTML for a single website page and persists it.
 * Runs in parallel within the building-stage batch for web_build experiments.
 */
class BuildWebsitePageJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public readonly string $experimentId,
        public readonly string $taskId,
        public readonly ?string $teamId = null,
        /** @var array<int, array{slug: string, title: string}> */
        public readonly array $allPages = [],
        public readonly string $publicBaseUrl = '',
    ) {
        $this->onQueue('ai-calls');
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(20);
    }

    public function middleware(): array
    {
        return [
            new CheckKillSwitch,
            new CheckBudgetAvailable,
            new TenantRateLimit('ai-calls', 30),
        ];
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $task = ExperimentTask::withoutGlobalScopes()->find($this->taskId);
        if (! $task || $task->status === ExperimentTaskStatus::Completed) {
            return;
        }

        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);
        if (! $experiment) {
            return;
        }

        $task->update([
            'status' => ExperimentTaskStatus::Running,
            'started_at' => now(),
        ]);

        $startTime = hrtime(true);

        try {
            $inputData = $task->input_data ?? [];
            $websiteId = $inputData['website_id'] ?? null;
            $pageSpec = $inputData['page_spec'] ?? [];

            $website = Website::withoutGlobalScopes()->findOrFail($websiteId);

            $html = $this->generatePageHtml($experiment->team_id, $website->name, $pageSpec, $this->allPages, $this->publicBaseUrl);

            $page = app(CreateWebsitePageAction::class)->execute($website, [
                'slug' => $pageSpec['slug'],
                'title' => $pageSpec['title'],
                'page_type' => $pageSpec['type'] ?? 'page',
                'exported_html' => $html,
                'grapes_json' => null,
                'meta' => [
                    'title' => $pageSpec['meta_title'] ?? $pageSpec['title'],
                    'description' => $pageSpec['meta_description'] ?? '',
                ],
            ]);

            app(PublishWebsitePageAction::class)->execute($page);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $task->update([
                'status' => ExperimentTaskStatus::Completed,
                'output_data' => [
                    'page_id' => $page->id,
                    'page_slug' => $page->slug,
                    'page_title' => $page->title,
                    'website_id' => $website->id,
                ],
                'duration_ms' => $durationMs,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $task->update([
                'status' => ExperimentTaskStatus::Failed,
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
                'completed_at' => now(),
            ]);

            Log::error('BuildWebsitePageJob: Failed to generate page', [
                'task_id' => $this->taskId,
                'experiment_id' => $this->experimentId,
                'page_spec' => $task->input_data['page_spec'] ?? [],
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $task = ExperimentTask::withoutGlobalScopes()->find($this->taskId);
        if (! $task || in_array($task->status, [ExperimentTaskStatus::Completed, ExperimentTaskStatus::Failed])) {
            return;
        }

        $task->update([
            'status' => ExperimentTaskStatus::Failed,
            'error' => $exception ? substr($exception->getMessage(), 0, 500) : 'Job killed by worker',
            'completed_at' => now(),
        ]);
    }

    /**
     * @param array<int, array{slug: string, title: string}> $allPages
     */
    private function generatePageHtml(string $teamId, string $siteName, array $pageSpec, array $allPages = [], string $publicBaseUrl = ''): string
    {
        $sections = implode(', ', $pageSpec['sections'] ?? ['hero', 'content', 'footer']);

        $team = \App\Domain\Shared\Models\Team::withoutGlobalScopes()->find($teamId);
        ['provider' => $provider, 'model' => $model] = app(ProviderResolver::class)->resolve(team: $team);

        // Build nav spec so every page has consistent navigation with correct hrefs
        $navSpec = '';
        if (count($allPages) > 1) {
            $navLinks = array_map(function (array $p) use ($publicBaseUrl) {
                $href = $publicBaseUrl ? "{$publicBaseUrl}/{$p['slug']}" : "/{$p['slug']}";
                return "- {$p['title']}: {$href}";
            }, $allPages);
            $navSpec = "\n\nNavigation (include in header/nav on every page with these exact hrefs):\n".implode("\n", $navLinks);
        }

        $response = app(AiGatewayInterface::class)->complete(new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'You are a web developer. Generate clean, modern HTML with inline Tailwind CSS classes. Return ONLY the HTML body content, no <html>/<head>/<body> tags. Always include a consistent navigation header with links to all provided pages.',
            userPrompt: "Generate HTML for the '{$pageSpec['title']}' page of '{$siteName}' website.\nSections to include: {$sections}\nPage description: ".($pageSpec['meta_description'] ?? '').$navSpec,
            maxTokens: 2048,
            teamId: $teamId,
        ));

        return $response->content;
    }
}
