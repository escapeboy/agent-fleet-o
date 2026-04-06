<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;
use App\Domain\Website\Services\GrapesJsExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public site API — no authentication required, rate-limited per IP.
 * Serves published page HTML and handles form submissions as FleetQ Signals.
 *
 * @tags Public Sites
 */
class PublicSiteController extends Controller
{
    /**
     * Serve a published page as standalone HTML.
     *
     * GET /api/public/sites/{siteSlug}/{pageSlug?}
     *
     * @response 200 HTML document
     * @response 404 {"message": "Not found."}
     */
    public function page(Request $request, string $siteSlug, string $pageSlug = 'index'): Response|JsonResponse
    {
        $website = Website::where('slug', $siteSlug)
            ->where('status', 'published')
            ->first();

        if (! $website) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $slug = $pageSlug === 'index' ? ($website->pages()->where('sort_order', 0)->value('slug') ?? 'index') : $pageSlug;

        $page = $website->pages()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->first();

        if (! $page) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        // Inject Tailwind Play CDN for AI-generated pages (which use Tailwind classes
        // but have no pre-built CSS). GrapesJS-built pages provide their own exported_css.
        $extraHead = [];
        if (empty($page->exported_css)) {
            $extraHead[] = '<script src="https://cdn.tailwindcss.com"></script>';
        }

        $canonicalUrl = url("/api/public/sites/{$siteSlug}/{$slug}");

        $html = app(GrapesJsExporter::class)->toStandaloneHtml(
            html: $page->exported_html ?? '',
            css: $page->exported_css ?? '',
            title: $page->getMetaTitle(),
            metaDescription: $page->getMetaDescription(),
            extraHead: $extraHead,
            canonicalUrl: $canonicalUrl,
        );

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // sanitize() already strips unauthorized scripts; CSP blocks object/embed injection and base-tag hijacking
            'Content-Security-Policy' => "object-src 'none'; base-uri 'self'",
        ]);
    }

    /**
     * Handle a form submission from a published website page.
     * Creates a FleetQ Signal of type `website_form` so workflows can react to it.
     *
     * POST /api/public/sites/{siteSlug}/forms/{formId}
     *
     * @response 200 {"message": "Submitted successfully."}
     * @response 404 {"message": "Not found."}
     * @response 422 {"message": "The payload field is required."}
     */
    public function submitForm(
        Request $request,
        IngestSignalAction $ingestSignal,
        string $siteSlug,
        string $formId,
    ): JsonResponse {
        $website = Website::where('slug', $siteSlug)
            ->where('status', 'published')
            ->first();

        if (! $website) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $request->validate([
            'payload' => ['required', 'array', 'max:50'],
            'payload.*' => ['nullable', 'string', 'max:1000'],
            'formId' => ['sometimes', 'string', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ]);

        $ingestSignal->execute(
            sourceType: 'website_form',
            sourceIdentifier: "{$siteSlug}/{$formId}",
            payload: array_merge(
                $request->input('payload', []),
                [
                    'form_id' => $formId,
                    'site_slug' => $siteSlug,
                    'page' => $request->input('page'),
                    'referrer' => substr($request->header('Referer') ?? '', 0, 500) ?: null,
                    'ip' => $request->ip(),
                ],
            ),
            teamId: $website->team_id,
        );

        return response()->json(['message' => 'Submitted successfully.']);
    }

    /**
     * List published pages metadata for a site (used by blog post-list block).
     *
     * GET /api/public/sites/{siteSlug}/pages
     *
     * @response 200 {"data": [{"slug": "...", "title": "...", "meta": {}, "published_at": "..."}]}
     */
    public function pages(string $siteSlug): JsonResponse
    {
        $website = Website::where('slug', $siteSlug)
            ->where('status', 'published')
            ->first();

        if (! $website) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $pages = $website->pages()
            ->where('status', 'published')
            ->orderBy('sort_order')
            ->get(['slug', 'title', 'page_type', 'meta', 'published_at', 'sort_order'])
            ->map(fn (WebsitePage $p) => [
                'slug' => $p->slug,
                'title' => $p->title,
                'page_type' => $p->page_type->value,
                'meta' => $p->meta,
                'published_at' => $p->published_at?->toISOString(),
            ]);

        return response()->json(['data' => $pages]);
    }
}
