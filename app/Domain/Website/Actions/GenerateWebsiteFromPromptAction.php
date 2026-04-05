<?php

namespace App\Domain\Website\Actions;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Models\Website;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Str;

class GenerateWebsiteFromPromptAction
{
    private ?Team $team = null;

    public function __construct(
        private readonly AiGatewayInterface $ai,
        private readonly CreateWebsiteAction $createWebsite,
        private readonly CreateWebsitePageAction $createPage,
        private readonly ProviderResolver $providerResolver,
    ) {}

    public function execute(string $teamId, string $prompt): Website
    {
        $this->team = Team::find($teamId);

        // Step 1: Generate site structure from prompt
        $structure = $this->generateStructure($teamId, $prompt);

        // Step 2: Create the website
        $website = $this->createWebsite->execute($teamId, $structure['name'], [
            'settings' => $structure['settings'] ?? [],
        ]);

        // Step 3: Generate and create each page
        foreach ($structure['pages'] as $pageSpec) {
            $html = $this->generatePageHtml($teamId, $website->name, $pageSpec);
            $this->createPage->execute($website, [
                'slug' => $pageSpec['slug'],
                'title' => $pageSpec['title'],
                'page_type' => $pageSpec['type'] ?? 'page',
                'exported_html' => $html,
                'grapes_json' => null, // populated when user opens editor
                'meta' => [
                    'title' => $pageSpec['meta_title'] ?? $pageSpec['title'],
                    'description' => $pageSpec['meta_description'] ?? '',
                ],
            ]);
        }

        return $website->load('pages');
    }

    private function generateStructure(string $teamId, string $prompt): array
    {
        ['provider' => $provider, 'model' => $model] = $this->providerResolver->resolve(team: $this->team);

        $response = $this->ai->complete(new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'You are a website architect. Return ONLY valid JSON, no markdown.',
            userPrompt: "Generate a website structure for this request: {$prompt}\n\n".
                'Return JSON: {"name": "...", "pages": [{"slug": "...", "title": "...", "type": "page|post|product|landing", "sections": ["hero","features","cta"], "meta_description": "..."}]}',
            maxTokens: 1024,
            teamId: $teamId,
        ));

        $json = $this->extractJson($response->content);

        return json_decode($json, true) ?? $this->fallbackStructure($prompt);
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

    private function extractJson(string $content): string
    {
        // Strip thinking tags if present
        $content = preg_replace('/<thinking>.*?<\/thinking>/s', '', $content);

        // Extract JSON from markdown code blocks
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $content, $matches)) {
            return $matches[1];
        }

        // Find raw JSON object
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            return $matches[0];
        }

        return $content;
    }

    private function fallbackStructure(string $prompt): array
    {
        $name = Str::title(Str::limit($prompt, 30, ''));

        return [
            'name' => $name,
            'pages' => [
                ['slug' => 'home', 'title' => 'Home', 'type' => 'landing', 'sections' => ['hero', 'features', 'cta'], 'meta_description' => ''],
                ['slug' => 'about', 'title' => 'About', 'type' => 'page', 'sections' => ['content'], 'meta_description' => ''],
                ['slug' => 'contact', 'title' => 'Contact', 'type' => 'page', 'sections' => ['form'], 'meta_description' => ''],
            ],
        ];
    }
}
