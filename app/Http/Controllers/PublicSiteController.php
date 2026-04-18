<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Enums\WebsitePageType;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Services\WebsiteWidgetRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicSiteController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $website = Website::where('slug', $slug)
            ->where('status', WebsiteStatus::Published)
            ->first();

        if (! $website) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json([
            'id' => $website->id,
            'name' => $website->name,
            'slug' => $website->slug,
            'settings' => $website->settings,
            'custom_domain' => $website->custom_domain,
        ]);
    }

    public function pages(string $slug): JsonResponse
    {
        $website = Website::where('slug', $slug)
            ->where('status', WebsiteStatus::Published)
            ->first();

        if (! $website) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $pages = $website->pages()
            ->where('status', WebsitePageStatus::Published)
            ->orderBy('sort_order')
            ->get(['id', 'slug', 'title', 'page_type', 'sort_order'])
            ->map(fn ($page) => [
                'id' => $page->id,
                'slug' => $page->slug,
                'title' => $page->title,
                'page_type' => $page->page_type->value,
                'sort_order' => $page->sort_order,
            ]);

        return response()->json($pages);
    }

    public function page(string $slug, string $pageSlug, WebsiteWidgetRenderer $widgets): JsonResponse
    {
        $website = Website::where('slug', $slug)
            ->where('status', WebsiteStatus::Published)
            ->first();

        if (! $website) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $page = $website->pages()
            ->where('slug', $pageSlug)
            ->where('status', WebsitePageStatus::Published)
            ->first();

        if (! $page) {
            return response()->json(['error' => 'Not found'], 404);
        }

        // Widget pass: replace <!-- fleetq:recent-posts --> etc. with rendered
        // snippets using team-scoped queries. Safe to run against AI-generated
        // HTML because the placeholder syntax is restrictive and all attribute
        // values are cast/validated in the renderer.
        $html = $widgets->render($page->exported_html ?? '', $website);

        return response()->json([
            'id' => $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            'page_type' => $page->page_type->value,
            'meta' => $page->meta,
            'exported_html' => $html,
        ])->withHeaders([
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function submitForm(Request $request, string $slug, string $formId): JsonResponse
    {
        // Honeypot — bots fill hidden fields, humans don't
        if ($request->filled('_hp')) {
            return response()->json(['success' => true]); // silent discard
        }

        $website = Website::where('slug', $slug)
            ->where('status', WebsiteStatus::Published)
            ->first();

        if (! $website) {
            return response()->json(['error' => 'Not found'], 404);
        }

        // Validate that the formId matches a known form on this website.
        // Prevents submissions with fabricated UUIDs that were never injected.
        $page = $website->pages()->where('form_id', $formId)->first();

        if (! $page) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $data = $request->validate([
            'fields' => ['array', 'max:50'],
            'fields.*' => ['string', 'max:1000'],
        ]);

        app(IngestSignalAction::class)->execute(
            sourceType: 'website_form',
            sourceIdentifier: $formId,
            payload: $data['fields'] ?? [],
            tags: [],
            experimentId: null,
            files: [],
            sourceNativeId: null,
            teamId: $website->team_id,
            senderHints: ['website_slug' => $slug, 'form_id' => $formId, 'page_slug' => $page->slug],
        );

        return response()->json(['success' => true]);
    }

    public function posts(string $slug): JsonResponse
    {
        $website = Website::where('slug', $slug)
            ->where('status', WebsiteStatus::Published)
            ->first();

        if (! $website) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $posts = $website->pages()
            ->where('page_type', WebsitePageType::Post)
            ->where('status', WebsitePageStatus::Published)
            ->orderByDesc('published_at')
            ->get(['id', 'slug', 'title', 'page_type', 'sort_order', 'published_at', 'meta'])
            ->map(fn ($page) => [
                'id' => $page->id,
                'slug' => $page->slug,
                'title' => $page->title,
                'page_type' => $page->page_type->value,
                'sort_order' => $page->sort_order,
                'published_at' => $page->published_at?->toISOString(),
                'meta' => $page->meta,
            ]);

        return response()->json($posts);
    }

    public function post(string $slug, string $postSlug): JsonResponse
    {
        $website = Website::where('slug', $slug)
            ->where('status', WebsiteStatus::Published)
            ->first();

        if (! $website) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $page = $website->pages()
            ->where('slug', $postSlug)
            ->where('page_type', WebsitePageType::Post)
            ->where('status', WebsitePageStatus::Published)
            ->first();

        if (! $page) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json([
            'id' => $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            'page_type' => $page->page_type->value,
            'meta' => $page->meta,
            'exported_html' => $page->exported_html,
            'published_at' => $page->published_at?->toISOString(),
        ])->withHeaders([
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
