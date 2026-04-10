<?php

namespace App\Domain\Website\Services;

use App\Domain\Shared\Scopes\TeamScope;
use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Enums\WebsitePageType;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;
use Illuminate\Support\Facades\Cache;

/**
 * Replaces widget placeholders in exported HTML at public serve time.
 *
 * Widgets are authored as HTML comments so they survive HtmlSanitizer, which
 * would strip custom tags:
 *
 *     <!-- fleetq:recent-posts limit="3" -->
 *     <!-- fleetq:page-list type="page" -->
 *
 * The renderer is invoked from PublicSiteController::page() after the page
 * HTML has been looked up but before it is returned to the caller. Widgets
 * query the database scoped to $website->team_id so they cannot leak data
 * across tenants.
 */
class WebsiteWidgetRenderer
{
    public const SUPPORTED = ['recent-posts', 'page-list'];

    /**
     * Maximum number of widget placeholders expanded per page. Widgets past
     * this cap are replaced with an empty string. The cap prevents a
     * malicious or runaway AI-generated page from issuing hundreds of DB
     * queries per public request.
     */
    public const MAX_WIDGETS_PER_PAGE = 20;

    /**
     * Short-TTL cache keeps rendered widget HTML in Redis for this many
     * seconds. Even if the content_version invalidation misses (observer
     * bug, race condition), stale data clears within this window.
     */
    public const CACHE_TTL_SECONDS = 60;

    public function render(string $html, Website $website): string
    {
        if (! str_contains($html, 'fleetq:')) {
            return $html;
        }

        $expanded = 0;
        // Per-call memoization: the same widget+attribute combination only
        // runs one cache lookup per render, no matter how many times it
        // appears on the page.
        $localCache = [];

        $result = preg_replace_callback(
            '/<!--\s*fleetq:([a-z][a-z0-9-]*)\s*([^>]*?)\s*-->/i',
            function (array $match) use ($website, &$expanded, &$localCache): string {
                if ($expanded >= self::MAX_WIDGETS_PER_PAGE) {
                    return '';
                }
                $expanded++;

                $widget = strtolower($match[1]);
                $attrs = $this->parseAttrs($match[2]);

                if (! in_array($widget, self::SUPPORTED, true)) {
                    return '';
                }

                ksort($attrs);
                $localKey = $widget.'|'.http_build_query($attrs);

                if (isset($localCache[$localKey])) {
                    return $localCache[$localKey];
                }

                $remoteKey = sprintf(
                    'fleet:widget:%s:%s:%s:%d',
                    $website->id,
                    $widget,
                    md5($localKey),
                    $website->content_version ?? 1,
                );

                // Manual hit/miss check so we can record observability
                // metrics — Cache::remember would hide the result from us.
                $cached = Cache::get($remoteKey);

                if ($cached !== null) {
                    app(WebsiteWidgetMetrics::class)->recordHit($widget);
                    $rendered = $cached;
                } else {
                    app(WebsiteWidgetMetrics::class)->recordMiss($widget);
                    $rendered = $this->renderWidget($widget, $website, $attrs);
                    Cache::put($remoteKey, $rendered, now()->addSeconds(self::CACHE_TTL_SECONDS));
                }

                $localCache[$localKey] = $rendered;

                return $rendered;
            },
            $html,
        );

        return $result ?? $html;
    }

    /**
     * Dispatch a widget by name. Separated from render() so the cache layer
     * can call it from inside a Cache::remember() closure.
     *
     * @param  array<string, string>  $attrs
     */
    private function renderWidget(string $widget, Website $website, array $attrs): string
    {
        return match ($widget) {
            'recent-posts' => $this->widgetRecentPosts($website, $attrs),
            'page-list' => $this->widgetPageList($website, $attrs),
            default => '',
        };
    }

    /**
     * @param  array<string, string>  $attrs
     */
    private function widgetRecentPosts(Website $website, array $attrs): string
    {
        $limit = $this->clampLimit($attrs['limit'] ?? '5');

        // withoutGlobalScopes() paired with explicit website_id + team_id.
        // The renderer is invoked from PublicSiteController::page() which
        // has NO authenticated user (public site serving), so TeamScope
        // would normally no-op. But in tests or any other caller with an
        // active auth context, TeamScope would filter by the caller's
        // current_team_id and hide posts from a different website's team.
        // The explicit where('team_id', $website->team_id) guarantees
        // isolation — this pattern is documented in
        // feedback_teamscope_includes_platform.md.
        $posts = WebsitePage::query()
            ->withoutGlobalScopes([TeamScope::class])
            ->where('website_id', $website->id)
            ->where('team_id', $website->team_id)
            ->where('page_type', WebsitePageType::Post)
            ->where('status', WebsitePageStatus::Published)
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get(['id', 'slug', 'title', 'meta', 'published_at']);

        if ($posts->isEmpty()) {
            return '';
        }

        $cards = $posts->map(function (WebsitePage $post): string {
            $title = e($post->title);
            $slug = e($post->slug);
            $excerpt = e((string) ($post->meta['excerpt'] ?? ''));
            $date = $post->published_at?->format('F j, Y') ?? '';

            return <<<HTML
<article style="border:1px solid #e2e8f0;border-radius:8px;padding:20px;background:#fff">
  <h3 style="font-size:18px;font-weight:600;margin:0 0 8px 0"><a href="/{$slug}" style="color:#1e293b;text-decoration:none">{$title}</a></h3>
  <p style="color:#64748b;font-size:13px;margin:0 0 8px 0">{$date}</p>
  <p style="color:#475569;font-size:14px;margin:0">{$excerpt}</p>
</article>
HTML;
        })->implode('');

        return '<section style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin:24px 0">'
            .$cards
            .'</section>';
    }

    /**
     * @param  array<string, string>  $attrs
     */
    private function widgetPageList(Website $website, array $attrs): string
    {
        $type = $this->resolvePageType($attrs['type'] ?? 'page');
        $limit = $this->clampLimit($attrs['limit'] ?? '50');

        // Same withoutGlobalScopes + explicit team_id pattern as
        // widgetRecentPosts. See the comment there for rationale.
        $pages = WebsitePage::query()
            ->withoutGlobalScopes([TeamScope::class])
            ->where('website_id', $website->id)
            ->where('team_id', $website->team_id)
            ->where('status', WebsitePageStatus::Published);

        if ($type !== null) {
            $pages->where('page_type', $type);
        }

        $pages = $pages
            ->orderBy('sort_order')
            ->limit($limit)
            ->get(['id', 'slug', 'title']);

        if ($pages->isEmpty()) {
            return '';
        }

        $items = $pages->map(
            fn (WebsitePage $p): string => '<li style="margin:6px 0"><a href="/'.e($p->slug).'" style="color:#4f46e5;text-decoration:none">'.e($p->title).'</a></li>',
        )->implode('');

        return '<ul style="list-style:none;padding:0;margin:16px 0">'.$items.'</ul>';
    }

    /**
     * @return array<string, string>
     */
    private function parseAttrs(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $attrs = [];
        if (preg_match_all('/([a-z][a-z0-9_-]*)="([^"]*)"/i', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attrs[strtolower($match[1])] = $match[2];
            }
        }

        return $attrs;
    }

    private function clampLimit(string $value): int
    {
        $n = (int) $value;
        if ($n < 1) {
            return 1;
        }
        if ($n > 50) {
            return 50;
        }

        return $n;
    }

    private function resolvePageType(string $value): ?WebsitePageType
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        return WebsitePageType::tryFrom($value);
    }
}
