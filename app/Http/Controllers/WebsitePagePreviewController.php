<?php

namespace App\Http\Controllers;

use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;
use Illuminate\Http\Response;

class WebsitePagePreviewController extends Controller
{
    public function __invoke(Website $website, WebsitePage $page): Response
    {
        abort_if($page->website_id !== $website->id, 404);

        $html = $page->exported_html ?? '<p>No content yet.</p>';
        $encoded = base64_encode($html);
        $nonce = base64_encode(random_bytes(16));

        $title = e($page->title);
        $slug = e($page->slug);

        $wrapper = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Preview: {$title}</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { background: #f8fafc; font-family: system-ui, sans-serif; }
                .preview-bar {
                    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
                    background: #1e293b; color: #94a3b8;
                    padding: 8px 16px; font-size: 13px;
                    display: flex; align-items: center; gap: 12px;
                }
                .preview-bar strong { color: #f1f5f9; }
                .preview-bar a { color: #818cf8; text-decoration: none; font-size: 12px; }
                iframe {
                    position: fixed; top: 37px; left: 0; right: 0; bottom: 0;
                    width: 100%; height: calc(100vh - 37px);
                    border: none; background: #fff;
                }
            </style>
        </head>
        <body>
            <div class="preview-bar">
                <strong>Preview</strong>
                <span>/{$slug}</span>
                <span style="margin-left:auto; font-size:12px; color:#64748b">sandboxed — scripts disabled</span>
            </div>
            <iframe
                id="preview-frame"
                sandbox="allow-forms"
                referrerpolicy="no-referrer"
            ></iframe>
            <script nonce="{$nonce}">
                const html = atob('{$encoded}');
                document.getElementById('preview-frame').srcdoc = html;
            </script>
        </body>
        </html>
        HTML;

        return response($wrapper, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; frame-src 'self'",
        ]);
    }
}
