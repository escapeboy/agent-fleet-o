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

        // Pre-compute slug so form action URIs are correct in Phase 1 HTML.
        $websiteSlug = Str::slug($name);

        $systemPrompt = <<<'PROMPT'
You are a website builder. Generate a complete, multi-page website as JSON.

You are generating ALL pages in a single response, so you know all page slugs at once.
Use this to create proper internal navigation and working forms.

Return ONLY valid JSON:
{
  "pages": [
    {
      "slug": "string (URL-safe, lowercase, hyphens)",
      "title": "string",
      "page_type": "landing|page|post|product",
      "html": "string (complete HTML for the page body)"
    }
  ]
}

CRITICAL RULES:
1. Always include a homepage with slug "index".
2. Use 3-5 pages. All page slugs will be known before you write any HTML.
3. Every page MUST contain a <nav> element with <a> links to every other page using their actual slugs (e.g. <a href="/about">About</a>). Internal hrefs must start with "/".
4. Contact or landing pages MUST include a real <form> element. Use:
   method="POST" action="/api/public/sites/WEBSITE_SLUG/forms/UNIQUE_ID"
   Replace WEBSITE_SLUG and generate a random UNIQUE_ID for each form.
5. Use inline CSS only (no external files or <link> tags).
6. Generate real, specific content — no Lorem Ipsum or placeholder text.
7. DYNAMIC WIDGETS: Prefer server-side widget markers over hardcoded lists when
   the page should show content that changes over time. The platform expands
   these HTML comment markers at serve time with live data, so you don't have
   to hand-write placeholder content. Supported widgets:
   - <!-- fleetq:recent-posts limit="3" --> — lists the most recent blog posts
     as cards linking to each post. Use on homepage hero sections, blog index
     pages, or "latest news" sidebars. Limit is optional, default 5, max 50.
   - <!-- fleetq:page-list type="page" --> — lists all published pages of the
     given type as a simple link list. Use for sitemaps and footer nav. Type
     must be one of: page, post, product, landing.
   Place the marker where you want the dynamic content to appear. Do NOT
   wrap it in a code block — output raw HTML comments.
PROMPT;

        $userPrompt = "Website name: {$name}\nWebsite slug: {$websiteSlug}\nDescription: {$prompt}\n\n"
            .'Generate the website structure as JSON. Remember: all pages share the same <nav>, '
            ."and any contact/landing page needs a <form action=\"/api/public/sites/{$websiteSlug}/forms/UNIQUE_ID\">.";

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: 8192,
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
            'slug' => $websiteSlug,
        ]);

        foreach ($decoded['pages'] as $pageData) {
            $page = app(CreateWebsitePageAction::class)->execute($website, [
                'slug' => $pageData['slug'] ?? Str::slug($pageData['title'] ?? 'page'),
                'title' => $pageData['title'] ?? 'Page',
                'page_type' => $pageData['page_type'] ?? 'page',
            ]);

            $html = HtmlSanitizer::purify($pageData['html'] ?? '');

            app(UpdateWebsitePageAction::class)->execute($page, [
                'exported_html' => $html,
                'exported_css' => '',
                'grapes_json' => null,
            ]);
        }

        // Phase 2: deterministic post-processing.
        // Guarantees every page has working navigation and contact pages have functional forms,
        // regardless of AI output quality.
        app(EnhanceWebsiteNavigationAction::class)->execute($website);

        return $website->load('pages');
    }
}
