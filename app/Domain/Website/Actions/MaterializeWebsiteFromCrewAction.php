<?php

namespace App\Domain\Website\Actions;

use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use Illuminate\Support\Facades\Log;

class MaterializeWebsiteFromCrewAction
{
    /** @var array<string> */
    private const ALLOWED_PAGE_TYPES = ['page', 'post', 'product', 'landing'];

    public function __construct(
        private readonly CreateWebsitePageAction $createPage,
    ) {}

    public function execute(CrewExecution $execution): void
    {
        // withoutGlobalScopes is intentional: runs in a queued job (no HTTP/team context)
        $website = Website::withoutGlobalScopes()
            ->where('crew_execution_id', $execution->id)
            ->first();

        if (! $website) {
            return;
        }

        try {
            $data = $this->extractWebsiteData($execution);

            if (empty($data['pages'])) {
                Log::warning('MaterializeWebsiteFromCrewAction: no pages in crew output', [
                    'execution_id' => $execution->id,
                    'website_id' => $website->id,
                ]);

                $website->update(['status' => WebsiteStatus::Draft, 'name' => 'Generated Website']);

                return;
            }

            $website->update([
                'name' => $data['website_name'] ?? 'Generated Website',
                'status' => WebsiteStatus::Draft,
            ]);

            foreach ($data['pages'] as $pageSpec) {
                if (empty($pageSpec['slug']) || empty($pageSpec['html'])) {
                    continue;
                }

                // Validate AI-supplied page_type against the allowed set
                $rawType = $pageSpec['type'] ?? 'page';
                $pageType = in_array($rawType, self::ALLOWED_PAGE_TYPES, true) ? $rawType : 'page';

                $this->createPage->execute($website, [
                    'slug' => $pageSpec['slug'],
                    'title' => $pageSpec['title'] ?? ucfirst($pageSpec['slug']),
                    'page_type' => $pageType,
                    'exported_html' => $this->cleanHtml($pageSpec['html']),
                    'grapes_json' => null,
                    'meta' => [
                        'title' => $pageSpec['title'] ?? ucfirst($pageSpec['slug']),
                        'description' => $pageSpec['meta_description'] ?? '',
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('MaterializeWebsiteFromCrewAction: materialization failed', [
                'execution_id' => $execution->id,
                'website_id' => $website->id,
                'error' => $e->getMessage(),
            ]);

            $website->update(['status' => WebsiteStatus::Draft, 'name' => 'Generated Website']);
        }
    }

    /**
     * Extract structured website data from crew final_output.
     * The synthesis prompt instructs the coordinator to produce JSON with
     * website_name and pages[]. We look for it in multiple locations.
     */
    private function extractWebsiteData(CrewExecution $execution): array
    {
        $output = $execution->final_output;

        if (empty($output)) {
            return [];
        }

        // Synthesize action wraps the result in {"result": {...}, "summary": "..."}
        $payload = $output['result'] ?? $output;

        if (is_string($payload)) {
            $payload = $this->parseJson($payload);
        }

        if (isset($payload['pages']) && is_array($payload['pages'])) {
            return $payload;
        }

        // Sometimes the coordinator wraps it one level deeper
        $nested = $payload['result'] ?? null;
        if (is_array($nested) && isset($nested['pages'])) {
            return $nested;
        }

        if (is_string($nested)) {
            $parsed = $this->parseJson($nested);
            if (isset($parsed['pages'])) {
                return $parsed;
            }
        }

        return [];
    }

    /**
     * Strip markdown code fences and LLM prose preamble from AI-generated HTML.
     * LLMs often wrap code in ```html...``` blocks or prefix it with prose.
     */
    private function cleanHtml(string $html): string
    {
        $html = trim($html);

        // Extract content from markdown code fence (```html...``` or ```...```)
        if (preg_match('/```(?:html)?\s*\n([\s\S]+?)\s*```/i', $html, $m)) {
            return trim($m[1]);
        }

        // Strip leading prose before the first HTML tag
        if (preg_match('/(<[a-z!][^\s>]*[\s\S]*)/i', $html, $m)) {
            $candidate = trim($m[1]);
            // Only use if it meaningfully starts the HTML (avoids matching stray inline tags)
            if (strlen($candidate) > 20) {
                return $candidate;
            }
        }

        return $html;
    }

    private function parseJson(string $content): array
    {
        $content = trim($content);

        // Strip markdown code fences
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/m', '', $content) ?? $content;
        $content = trim($content);

        // Try direct JSON decode first (cheapest path, handles clean output)
        $direct = json_decode($content, true);
        if (is_array($direct)) {
            return $direct;
        }

        // Greedy match: find first '{' to last '}' — this correctly captures JSON even when
        // string values contain braces (e.g. CSS or HTML embedded in "html" property).
        // A bracket-counting approach would terminate too early on '}' inside string values.
        if (preg_match('/\{[\s\S]*\}/u', $content, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
