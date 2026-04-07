<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebsitePageType;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;
use Illuminate\Support\Str;

class EnhanceWebsiteNavigationAction
{
    public function execute(Website $website): void
    {
        $pages = $website->pages()->orderBy('sort_order')->get(['id', 'slug', 'title', 'page_type', 'exported_html']);

        if ($pages->isEmpty()) {
            return;
        }

        $navLinks = $pages->map(fn (WebsitePage $p) => '<a href="/'.e($p->slug).'" '
            .'style="color:#e2e8f0;text-decoration:none;padding:0 14px;font-size:14px;font-weight:500">'
            .e($p->title).'</a>',
        )->implode('');

        $nav = '<nav style="background:#1e293b;color:#e2e8f0;padding:14px 24px;'
            .'display:flex;align-items:center;flex-wrap:wrap;gap:4px">'
            .$navLinks.'</nav>';

        foreach ($pages as $page) {
            $html = $page->exported_html ?? '';
            $html = $this->injectNavigation($html, $nav);
            $html = $this->injectContactForm($html, $page, $website->slug);

            // Direct update — HTML was already sanitized in Phase 1.
            // Skipping UpdateWebsitePageAction avoids a redundant sanitizer pass
            // and preserves injected form elements that are now whitelisted.
            $page->update(['exported_html' => $html]);
        }
    }

    private function injectNavigation(string $html, string $nav): string
    {
        // Replace first existing <nav> block (AI may have generated one)
        if (preg_match('/<nav[\s>]/i', $html)) {
            $replaced = preg_replace('/<nav\b[^>]*>.*?<\/nav>/is', $nav, $html, 1);

            return $replaced ?? $html;
        }

        // No <nav> — prepend to page content
        return $nav.$html;
    }

    private function injectContactForm(string $html, WebsitePage $page, string $websiteSlug): string
    {
        // Skip pages that already have a functional form
        if (stripos($html, '<form') !== false) {
            return $html;
        }

        $wantsForm = $page->page_type === WebsitePageType::Landing
            || stripos($html, 'contact') !== false
            || stripos($html, 'get in touch') !== false
            || stripos($html, 'reach us') !== false;

        if (! $wantsForm) {
            return $html;
        }

        $formId = (string) Str::uuid();
        $action = e('/api/public/'.$websiteSlug.'/forms/'.$formId.'/submit');

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
      <button type="submit"
              style="padding:12px 24px;background:#4f46e5;color:#fff;border:none;border-radius:6px;font-size:15px;cursor:pointer;font-weight:600">
        Send message
      </button>
    </form>
  </div>
</section>
HTML;

        return $html.$form;
    }
}
