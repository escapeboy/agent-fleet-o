<?php

namespace App\Domain\Website\Actions;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Str;

class GenerateWebsiteStructureAction
{
    public function __construct(
        private readonly AiGatewayInterface $ai,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * Generate a website structure (name + pages array) from a natural language prompt.
     *
     * @return array{name: string, pages: array<array{slug: string, title: string, type: string, sections: string[], meta_description: string}>}
     */
    public function execute(string $teamId, string $prompt): array
    {
        $team = Team::withoutGlobalScopes()->find($teamId);
        ['provider' => $provider, 'model' => $model] = $this->providerResolver->resolve(team: $team);

        $response = $this->ai->complete(new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'You are a website architect. Return ONLY valid JSON, no markdown.',
            userPrompt: "Generate a website structure for this request: {$prompt}\n\n"
                .'Return JSON: {"name": "...", "pages": [{"slug": "...", "title": "...", "type": "page|post|product|landing", "sections": ["hero","features","cta"], "meta_description": "..."}]}'
                ."\n\nIMPORTANT: Do NOT use 'products' as a page slug — use 'catalog' instead.",
            maxTokens: 1024,
            teamId: $teamId,
        ));

        $json = $this->extractJson($response->content);
        $structure = json_decode($json, true);

        return $structure ?? $this->fallback($prompt);
    }

    private function extractJson(string $content): string
    {
        $content = preg_replace('/<thinking>.*?<\/thinking>/s', '', $content);

        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $content, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            return $matches[0];
        }

        return $content;
    }

    private function fallback(string $prompt): array
    {
        return [
            'name' => Str::title(Str::limit($prompt, 30, '')),
            'pages' => [
                ['slug' => 'home', 'title' => 'Home', 'type' => 'landing', 'sections' => ['hero', 'features', 'cta'], 'meta_description' => ''],
                ['slug' => 'about', 'title' => 'About', 'type' => 'page', 'sections' => ['content'], 'meta_description' => ''],
                ['slug' => 'contact', 'title' => 'Contact', 'type' => 'page', 'sections' => ['form'], 'meta_description' => ''],
            ],
        ];
    }
}
