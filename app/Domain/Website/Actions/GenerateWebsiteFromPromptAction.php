<?php

namespace App\Domain\Website\Actions;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Models\Website;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;

class GenerateWebsiteFromPromptAction
{
    private ?Team $team = null;

    public function __construct(
        private readonly AiGatewayInterface $ai,
        private readonly GenerateWebsiteStructureAction $generateStructure,
        private readonly CreateWebsiteAction $createWebsite,
        private readonly CreateWebsitePageAction $createPage,
        private readonly ProviderResolver $providerResolver,
    ) {}

    public function execute(string $teamId, string $prompt): Website
    {
        $this->team = Team::find($teamId);

        $structure = $this->generateStructure->execute($teamId, $prompt);

        $website = $this->createWebsite->execute($teamId, $structure['name'], [
            'settings' => $structure['settings'] ?? [],
        ]);

        foreach ($structure['pages'] as $pageSpec) {
            $html = $this->generatePageHtml($teamId, $website->name, $pageSpec);
            $this->createPage->execute($website, [
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
        }

        return $website->load('pages');
    }

    private function generatePageHtml(string $teamId, string $siteName, array $pageSpec): string
    {
        $sections = implode(', ', $pageSpec['sections'] ?? ['hero', 'content', 'footer']);

        ['provider' => $provider, 'model' => $model] = $this->providerResolver->resolve(team: $this->team);

        $response = $this->ai->complete(new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'You are a web developer. Generate clean, modern HTML with inline Tailwind CSS classes. Return ONLY the HTML body content, no <html>/<head>/<body> tags.',
            userPrompt: "Generate HTML for the '{$pageSpec['title']}' page of '{$siteName}' website.\nSections to include: {$sections}\nPage description: ".($pageSpec['meta_description'] ?? ''),
            maxTokens: 4096,
            teamId: $teamId,
        ));

        return $response->content;
    }
}
