<?php

namespace App\Domain\Website\Actions;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\CreateCrewAction;
use App\Domain\Crew\Actions\ExecuteCrewAction;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Shared\Models\Team;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Facades\Log;

class GenerateWebsiteWithCrewAction
{
    public function __construct(
        private readonly CreateWebsiteAction $createWebsite,
        private readonly CreateCrewAction $createCrew,
        private readonly ExecuteCrewAction $executeCrew,
        private readonly ProviderResolver $providerResolver,
    ) {}

    public function execute(string $teamId, string $prompt): Website
    {
        // Validate at the action boundary (in case called outside Livewire)
        if (strlen($prompt) > 2000) {
            throw new \InvalidArgumentException('Prompt must not exceed 2000 characters.');
        }

        $team = Team::find($teamId);
        $resolved = $this->providerResolver->resolve(team: $team);

        // Create the website first so we always have a record to roll back on error
        $website = $this->createWebsite->execute($teamId, 'Generating…', [
            'status' => WebsiteStatus::Generating,
        ]);

        try {
            // Resolve agents inside the try/catch so seeding failures are handled gracefully
            [$architect, $developer, $qaAgent] = $this->resolveAgents($teamId, $resolved);

            $userId = auth()->id() ?? $team->owner?->id;

            $crew = $this->createCrew->execute(
                userId: $userId,
                name: 'Website Generation: '.strip_tags(substr($prompt, 0, 60)),
                coordinatorAgentId: $architect->id,
                qaAgentId: $qaAgent->id,
                description: "Generate a complete website from prompt: {$prompt}",
                processType: CrewProcessType::Sequential,
                maxTaskIterations: 2,
                qualityThreshold: 0.60,
                workerAgentIds: [$developer->id],
                teamId: $teamId,
            );

            // Activate the crew so ExecuteCrewAction allows execution
            $crew->update(['status' => CrewStatus::Active]);
            $crew->refresh();

            $goal = $this->buildGoal($prompt);

            $execution = $this->executeCrew->execute($crew, $goal, $teamId);

            $website->update(['crew_execution_id' => $execution->id]);
        } catch (\Throwable $e) {
            Log::error('GenerateWebsiteWithCrewAction: crew setup failed', [
                'website_id' => $website->id,
                'error' => $e->getMessage(),
            ]);

            // Fall back to draft so the user can retry
            $website->update(['status' => WebsiteStatus::Draft, 'name' => 'Failed generation']);
        }

        return $website;
    }

    /**
     * @return array{Agent, Agent, Agent} [architect, developer, qa]
     */
    private function resolveAgents(string $teamId, array $resolved): array
    {
        $slugs = ['web-architect', 'web-developer', 'web-qa'];
        $agents = Agent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereIn('slug', $slugs)
            ->where('status', AgentStatus::Active)
            ->get()
            ->keyBy('slug');

        if ($agents->count() < 3) {
            $this->seedWebAgents($teamId, $resolved['provider'], $resolved['model']);

            $agents = Agent::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->whereIn('slug', $slugs)
                ->where('status', AgentStatus::Active)
                ->get()
                ->keyBy('slug');
        }

        if ($agents->count() < 3) {
            throw new \RuntimeException('Could not resolve all required web agents after seeding.');
        }

        return [
            $agents['web-architect'],
            $agents['web-developer'],
            $agents['web-qa'],
        ];
    }

    private function seedWebAgents(string $teamId, string $provider, string $model): void
    {
        $definitions = [
            [
                'slug' => 'web-architect',
                'name' => 'Web Architect',
                'role' => 'Website architect and project coordinator',
                'goal' => 'Decompose website generation goals into clear page-by-page tasks, then synthesize the results into a structured JSON website definition.',
                'backstory' => 'You are an expert web architect who plans and coordinates the creation of complete websites. You produce structured outputs that web developers can implement.',
            ],
            [
                'slug' => 'web-developer',
                'name' => 'Web Developer',
                'role' => 'Frontend web developer',
                'goal' => 'Generate clean, modern HTML with inline Tailwind CSS for individual website pages based on the architect\'s specifications.',
                'backstory' => 'You are a skilled frontend developer who creates visually appealing, responsive web pages using Tailwind CSS. You produce complete HTML body content without html/head/body wrapper tags.',
            ],
            [
                'slug' => 'web-qa',
                'name' => 'Web QA',
                'role' => 'Website quality assurance reviewer',
                'goal' => 'Verify that generated website pages are complete, well-structured, and meet the original requirements.',
                'backstory' => 'You are a thorough QA engineer who reviews web content for completeness, visual quality, and adherence to requirements.',
            ],
        ];

        foreach ($definitions as $def) {
            Agent::withoutGlobalScopes()->updateOrCreate(
                ['team_id' => $teamId, 'slug' => $def['slug']],
                [
                    'name' => $def['name'],
                    'role' => $def['role'],
                    'goal' => $def['goal'],
                    'backstory' => $def['backstory'],
                    'provider' => $provider,
                    'model' => $model,
                    'status' => AgentStatus::Active,
                    'config' => ['max_tokens' => 4096],
                ],
            );
        }
    }

    private function buildGoal(string $prompt): string
    {
        return <<<GOAL
Create a complete website based on the following user description.

[USER_DESCRIPTION_START]
{$prompt}
[USER_DESCRIPTION_END]

The Web Architect must decompose this into tasks: one task per page (home, about, contact, etc.).
For each page, describe what the Web Developer should build: title, slug, type (landing/page/post), and sections.

The Web Developer generates complete HTML body content for each page using Tailwind CSS classes.
Do NOT include <html>, <head>, or <body> tags — only the inner body content.

When all pages are done, the Web Architect synthesizes the final result as JSON:
{
  "website_name": "...",
  "pages": [
    {
      "slug": "home",
      "title": "Home",
      "type": "landing",
      "html": "... full Tailwind HTML ...",
      "meta_description": "..."
    }
  ]
}
GOAL;
    }
}
