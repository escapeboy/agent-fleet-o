<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Enums\WebsitePageType;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EnhanceWebsiteNavigationAction
{
    /**
     * Re-enhance every page in the website: rebuild the nav, validate internal
     * links, and inject contact forms where appropriate.
     *
     * @param  bool  $publishedOnly  When true, the nav only lists published pages.
     *                               Used by post-mutation hooks so drafts don't
     *                               appear in the public navigation. Default false
     *                               preserves the behaviour of the initial AI
     *                               generation path (all pages are drafts until
     *                               the user publishes them).
     */
    public function execute(Website $website, bool $publishedOnly = false): void
    {
        // Validate slug before using it in URL construction (defense-in-depth).
        // Str::slug() already guarantees this at creation time, but the slug
        // could be modified via direct DB access or the API.
        if (! preg_match('/^[a-z0-9-]+$/', $website->slug)) {
            throw new \InvalidArgumentException("Invalid website slug: {$website->slug}");
        }

        // Page set to enhance. When $publishedOnly is true we still update every
        // page's HTML (so a newly-unpublished page can also be freshened), but
        // the NAV only lists the published pages.
        $allPages = $website->pages()->orderBy('sort_order')->get(['id', 'slug', 'title', 'page_type', 'status', 'exported_html', 'form_id']);

        if ($allPages->isEmpty()) {
            return;
        }

        $navPages = $publishedOnly
            ? $allPages->where('status', WebsitePageStatus::Published)->values()
            : $allPages;

        $nav = $this->buildNav($navPages);
        $validPaths = $this->buildValidPathSet($navPages);

        foreach ($allPages as $page) {
            $html = $page->exported_html ?? '';
            $html = $this->injectNavigation($html, $nav);
            $html = $this->rewriteInternalLinks($html, $validPaths);
            [$html, $formId] = $this->injectContactForm($html, $page, $website->slug);

            // Direct update — HTML was already sanitized in Phase 1.
            // We bypass UpdateWebsitePageAction here because:
            // 1. The nav and form HTML is server-generated (trusted), not user input.
            // 2. HtmlSanitizer strips form[action] (to prevent phishing). Re-running
            //    it would remove the safe /api/public/... action we just injected.
            $update = ['exported_html' => $html];

            if ($formId !== null) {
                $update['form_id'] = $formId;
            }

            $page->update($update);
        }
    }

    /**
     * @param  Collection<int, WebsitePage>  $pages
     */
    private function buildNav(Collection $pages): string
    {
        if ($pages->isEmpty()) {
            return '<nav style="background:#1e293b;color:#e2e8f0;padding:14px 24px"></nav>';
        }

        $navLinks = $pages->map(fn (WebsitePage $p) => '<a href="/'.e($p->slug).'" '
            .'style="color:#e2e8f0;text-decoration:none;padding:0 14px;font-size:14px;font-weight:500">'
            .e($p->title).'</a>',
        )->implode('');

        return '<nav style="background:#1e293b;color:#e2e8f0;padding:14px 24px;'
            .'display:flex;align-items:center;flex-wrap:wrap;gap:4px">'
            .$navLinks.'</nav>';
    }

    /**
     * Internal paths that a link is allowed to target. Homepage ("/") is always
     * valid. Page paths like "/about" are added per page.
     *
     * @param  Collection<int, WebsitePage>  $pages
     * @return array<int, string>
     */
    private function buildValidPathSet(Collection $pages): array
    {
        $paths = ['/'];

        foreach ($pages as $page) {
            $paths[] = '/'.$page->slug;

            if (in_array($page->slug, ['index', 'home'], true)) {
                // Root is already in the list.
                continue;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Rewrite broken internal links to "/" (homepage).
     *
     * Scans every <a href="/..."> in the HTML and checks whether the target
     * path matches a real page slug. Unknown paths are rewritten so users
     * don't land on a 404. Preserves external URLs, anchor-only links,
     * API endpoints, protocol-relative URLs, and mailto/tel schemes.
     *
     * @param  array<int, string>  $validPaths
     */
    private function rewriteInternalLinks(string $html, array $validPaths): string
    {
        $result = preg_replace_callback(
            '/(<a\s(?:[^>]*?\s)?)href="([^"]*)"([^>]*>)/i',
            function (array $match) use ($validPaths): string {
                $href = $match[2];

                // Empty or anchor-only: leave it alone.
                if ($href === '' || $href[0] === '#') {
                    return $match[0];
                }

                // External URLs, mailto, tel, protocol-relative: leave alone.
                if (str_starts_with($href, 'http://')
                    || str_starts_with($href, 'https://')
                    || str_starts_with($href, 'mailto:')
                    || str_starts_with($href, 'tel:')
                    || str_starts_with($href, '//')) {
                    return $match[0];
                }

                // API endpoints (form actions, asset fetches): leave alone.
                if (str_starts_with($href, '/api/')) {
                    return $match[0];
                }

                // Only rewrite root-relative paths like "/learn-more".
                if ($href[0] !== '/') {
                    return $match[0];
                }

                // Strip query + fragment before matching.
                $path = strtok($href, '?#');

                if (in_array($path, $validPaths, true)) {
                    return $match[0];
                }

                return $match[1].'href="/"'.$match[3];
            },
            $html,
        );

        // preg_replace_callback returns null on regex error (PREG_BACKTRACK_LIMIT_ERROR
        // etc.). Fall back to original HTML rather than corrupting the page.
        return $result ?? $html;
    }

    private function injectNavigation(string $html, string $nav): string
    {
        // Replace first existing <nav> block (AI may have generated one)
        if (preg_match('/<nav[\s>]/i', $html)) {
            $replaced = preg_replace('/<nav\b[^>]*>.*?<\/nav>/is', $nav, $html, 1);

            if ($replaced === null) {
                // preg_replace failed (e.g. PREG_BACKTRACK_LIMIT_ERROR) — fall back to prepend
                return $nav.$html;
            }

            return $replaced;
        }

        // No <nav> — prepend to page content
        return $nav.$html;
    }

    /**
     * @return array{string, string|null} [html, formId|null]
     */
    private function injectContactForm(string $html, WebsitePage $page, string $websiteSlug): array
    {
        // HtmlSanitizer strips form[action] to prevent phishing — AI-generated forms
        // will have their action removed. Skip only if the form already has an action
        // pointing to our API (i.e., was injected by this action in a prior run).
        if (stripos($html, '/api/public/') !== false && stripos($html, '<form') !== false) {
            return [$html, $page->form_id]; // preserve existing form_id
        }

        $wantsForm = $page->page_type === WebsitePageType::Landing
            || stripos($html, 'contact') !== false
            || stripos($html, 'get in touch') !== false
            || stripos($html, 'reach us') !== false;

        if (! $wantsForm) {
            return [$html, null];
        }

        $formId = (string) Str::uuid();
        // $websiteSlug is pre-validated to /^[a-z0-9-]+$/; e() as belt-and-suspenders.
        $action = e('/api/public/sites/'.$websiteSlug.'/forms/'.$formId);

        $form = <<<HTML
<section style="background:#f8fafc;padding:48px 24px;margin-top:32px">
  <div style="max-width:560px;margin:0 auto">
    <h2 style="font-size:22px;font-weight:700;margin-bottom:24px;text-align:center;color:#1e293b">Get in touch</h2>
    <form method="POST" action="{$action}" style="display:flex;flex-direction:column;gap:16px">
      <input type="text" name="fields[name]" placeholder="Your name" required
             style="padding:12px 16px;border:1px solid #cbd5e1;border-radius:6px;font-size:15px">
      <input type="email" name="fields[email]" placeholder="Email address" required
             style="padding:12px 16px;border:1px solid #cbd5e1;border-radius:6px;font-size:15px">
      <textarea name="fields[message]" placeholder="Your message" rows="4" required
                style="padding:12px 16px;border:1px solid #cbd5e1;border-radius:6px;font-size:15px;resize:vertical"></textarea>
      <input type="text" name="_hp" style="display:none;position:absolute;left:-9999px" tabindex="-1" autocomplete="off">
      <button type="submit"
              style="padding:12px 24px;background:#4f46e5;color:#fff;border:none;border-radius:6px;font-size:15px;cursor:pointer;font-weight:600">
        Send message
      </button>
    </form>
  </div>
</section>
HTML;

        return [$html.$form, $formId];
    }
}
