<?php

namespace App\Domain\Website\Actions;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Services\HtmlSanitizer;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateWebsiteFromPromptAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    public function execute(Team $team, string $prompt, string $name): Website
    {
        try {
            $resolved = $this->providerResolver->resolve(team: $team);
            $provider = $resolved['provider'];
            $model = $resolved['model'];
        } catch (\Throwable) {
            $provider = 'anthropic';
            $model = 'claude-haiku-4-5-20251001';
        }

        $systemPrompt = <<<'PROMPT'
You are a website architect. Given a description, generate a website structure as JSON.

Return ONLY valid JSON matching this schema:
{
  "pages": [
    {
      "slug": "string (URL-safe, lowercase, hyphens)",
      "title": "string",
      "page_type": "landing|page|post|product",
      "html": "string (complete HTML content for the page body, using inline styles)"
    }
  ]
}

Guidelines:
- Always include a home page with slug "index"
- Use 3-5 pages for a typical website
- Generate real, useful HTML content for each page — not placeholders
- Use inline CSS styles (no external CSS files)
- Pages should be complete and standalone
PROMPT;

        $userPrompt = "Website name: {$name}\nDescription: {$prompt}\n\nGenerate the website structure as JSON.";

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: 4096,
            purpose: 'website_generation',
            teamId: $team->id,
        ));

        $text = trim($response->content);

        // Strip markdown code fences if present
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = preg_replace('/\s*```\s*$/', '', $text);
        }

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! isset($decoded['pages']) || ! is_array($decoded['pages'])) {
            Log::warning('GenerateWebsiteFromPromptAction: Invalid JSON response', [
                'error' => json_last_error_msg(),
                'text_preview' => substr($text, 0, 200),
            ]);

            throw new \RuntimeException('Failed to parse AI response as JSON');
        }

        $website = app(CreateWebsiteAction::class)->execute($team, [
            'name' => $name,
            'slug' => Str::slug($name),
        ]);

        foreach ($decoded['pages'] as $pageData) {
            $page = app(CreateWebsitePageAction::class)->execute($website, [
                'slug' => $pageData['slug'] ?? Str::slug($pageData['title'] ?? 'page'),
                'title' => $pageData['title'] ?? 'Page',
                'page_type' => $pageData['page_type'] ?? 'page',
            ]);

            $html = HtmlSanitizer::purify($pageData['html'] ?? '');
            $css = '';

            app(UpdateWebsitePageAction::class)->execute($page, [
                'exported_html' => $html,
                'exported_css' => $css,
                'grapes_json' => null,
            ]);
        }

        return $website->load('pages');
    }
}
