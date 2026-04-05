<?php

namespace App\Domain\Website\Services;

use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;
use ZipArchive;

class WebsiteZipBuilder
{
    public function __construct(
        private readonly GrapesJsExporter $exporter,
    ) {}

    /**
     * Build a deployable ZIP archive for the website.
     * Returns the temporary path to the ZIP file.
     */
    public function build(Website $website): string
    {
        $zipPath = storage_path("app/tmp/website-{$website->id}-".now()->timestamp.'.zip');

        @mkdir(dirname($zipPath), recursive: true);

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        try {
            $publishedPages = $website->publishedPages()->get();

            foreach ($publishedPages as $page) {
                $html = $this->buildPageHtml($website, $page, $publishedPages->all());
                $filename = $page->slug === 'home' ? 'index.html' : "{$page->slug}/index.html";
                $zip->addFromString($filename, $html);
            }

            // Add a minimal _redirects file for Cloudflare Pages / Netlify SPA routing
            $zip->addFromString('_redirects', "/* /index.html 200\n");

            // Add FleetQ site JS (form handler + chatbot loader)
            $zip->addFromString('fleetq-site.js', $this->buildSiteJs($website));

            $zip->close();
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($zipPath);
            throw $e;
        }

        return $zipPath;
    }

    private function buildPageHtml(Website $website, WebsitePage $page, array $allPages): string
    {
        $navLinks = collect($allPages)
            ->map(fn ($p) => sprintf(
                '<a href="/%s" class="nav-link">%s</a>',
                $p->slug === 'home' ? '' : $p->slug,
                htmlspecialchars($p->title),
            ))
            ->implode("\n");

        $extraHead = [
            '<script src="/fleetq-site.js" data-fleetq data-site="'.$website->slug.'"></script>',
        ];

        return $this->exporter->toStandaloneHtml(
            html: $page->exported_html ?? '',
            css: $page->exported_css ?? '',
            title: $page->getMetaTitle().' — '.$website->name,
            metaDescription: $page->getMetaDescription(),
            extraHead: $extraHead,
        );
    }

    /**
     * Minimal JS bundle injected into every page.
     * Handles: form submission → FleetQ API, chatbot widget loader.
     */
    private function buildSiteJs(Website $website): string
    {
        $apiBase = rtrim(config('app.url'), '/');
        $siteSlug = $website->slug;

        return <<<JS
        (function() {
            const FLEETQ_API = '{$apiBase}/api/public/sites/{$siteSlug}';

            // Form handler: POST form data → FleetQ Signal
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('[data-fleetq-form]').forEach(function(form) {
                    form.addEventListener('submit', function(e) {
                        e.preventDefault();
                        var formId = form.dataset.fleetqForm;
                        var data = Object.fromEntries(new FormData(form));
                        fetch(FLEETQ_API + '/forms/' + formId, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(data)
                        }).then(function(r) {
                            if (r.ok) {
                                var msg = form.querySelector('[data-success]');
                                if (msg) { form.style.display='none'; msg.style.display='block'; }
                            }
                        });
                    });
                });
            });
        })();
        JS;
    }
}
