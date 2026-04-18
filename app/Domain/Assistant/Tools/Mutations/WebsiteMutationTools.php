<?php

namespace App\Domain\Assistant\Tools\Mutations;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Actions\CreateWebsiteAction;
use App\Domain\Website\Actions\CreateWebsitePageAction;
use App\Domain\Website\Actions\DeleteWebsiteAction;
use App\Domain\Website\Actions\DeleteWebsitePageAction;
use App\Domain\Website\Actions\GenerateWebsiteFromPromptAction;
use App\Domain\Website\Actions\PublishWebsitePageAction;
use App\Domain\Website\Actions\UnpublishWebsiteAction;
use App\Domain\Website\Actions\UnpublishWebsitePageAction;
use App\Domain\Website\Actions\UpdateWebsiteAction;
use App\Domain\Website\Actions\UpdateWebsitePageAction;
use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Enums\WebsitePageType;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

final class WebsiteMutationTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function writeTools(): array
    {
        return [
            self::createWebsite(),
            self::updateWebsite(),
            self::generateWebsite(),
            self::publishWebsite(),
            self::unpublishWebsite(),
            self::createWebsitePage(),
            self::updateWebsitePage(),
            self::publishWebsitePage(),
            self::unpublishWebsitePage(),
        ];
    }

    /**
     * @return array<PrismToolObject>
     */
    public static function destructiveTools(): array
    {
        return [
            self::deleteWebsite(),
            self::deleteWebsitePage(),
        ];
    }

    public static function createWebsite(): PrismToolObject
    {
        return PrismTool::as('create_website')
            ->for('Create a new website in FleetQ. Returns the website id and builder URL.')
            ->withStringParameter('name', 'Human-readable website name', required: true)
            ->withStringParameter('slug', 'Optional URL slug (auto-generated from name if omitted)')
            ->using(function (string $name, ?string $slug = null) {
                $team = auth()->user()->currentTeam;
                if (! $team instanceof Team) {
                    return json_encode(['error' => 'No active team']);
                }

                try {
                    $website = app(CreateWebsiteAction::class)->execute(
                        team: $team,
                        data: array_filter([
                            'name' => $name,
                            'slug' => $slug,
                        ], fn ($v) => $v !== null),
                        user: auth()->user(),
                    );

                    return json_encode([
                        'success' => true,
                        'website_id' => $website->id,
                        'name' => $website->name,
                        'slug' => $website->slug,
                        'status' => $website->status->value,
                        'url' => route('websites.show', $website),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function updateWebsite(): PrismToolObject
    {
        return PrismTool::as('update_website')
            ->for('Update an existing website name, slug, or custom domain')
            ->withStringParameter('website_id', 'The website UUID', required: true)
            ->withStringParameter('name', 'New website name')
            ->withStringParameter('slug', 'New URL slug')
            ->withStringParameter('custom_domain', 'New custom domain (use empty string to clear)')
            ->using(function (string $website_id, ?string $name = null, ?string $slug = null, ?string $custom_domain = null) {
                $website = Website::query()->find($website_id);
                if (! $website) {
                    return json_encode(['error' => 'Website not found']);
                }

                $data = [];
                if ($name !== null) {
                    $data['name'] = $name;
                }
                if ($slug !== null) {
                    $data['slug'] = $slug;
                }
                if ($custom_domain !== null) {
                    $data['custom_domain'] = $custom_domain === '' ? null : $custom_domain;
                }

                if (empty($data)) {
                    return json_encode(['error' => 'No attributes provided to update']);
                }

                try {
                    $website = app(UpdateWebsiteAction::class)->execute($website, $data);

                    return json_encode([
                        'success' => true,
                        'website_id' => $website->id,
                        'name' => $website->name,
                        'slug' => $website->slug,
                        'custom_domain' => $website->custom_domain,
                        'status' => $website->status->value,
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function generateWebsite(): PrismToolObject
    {
        return PrismTool::as('generate_website')
            ->for('Generate a new website from a natural-language prompt using AI. Creates the website plus pages in one shot.')
            ->withStringParameter('prompt', 'What the website should be about, who it is for, what pages it needs', required: true)
            ->withStringParameter('name', 'Website name', required: true)
            ->using(function (string $prompt, string $name) {
                $team = auth()->user()->currentTeam;
                if (! $team instanceof Team) {
                    return json_encode(['error' => 'No active team']);
                }

                try {
                    $website = app(GenerateWebsiteFromPromptAction::class)->execute(
                        team: $team,
                        prompt: $prompt,
                        name: $name,
                    );

                    return json_encode([
                        'success' => true,
                        'website_id' => $website->id,
                        'name' => $website->name,
                        'slug' => $website->slug,
                        'page_count' => $website->pages()->count(),
                        'url' => route('websites.show', $website),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function publishWebsite(): PrismToolObject
    {
        return PrismTool::as('publish_website')
            ->for('Publish a website and all its draft pages that have exported HTML. Makes the site accessible at /api/public/sites/{slug}.')
            ->withStringParameter('website_id', 'The website UUID', required: true)
            ->using(function (string $website_id) {
                $website = Website::query()->find($website_id);
                if (! $website) {
                    return json_encode(['error' => 'Website not found']);
                }

                try {
                    $publishAction = app(PublishWebsitePageAction::class);
                    $publishedCount = 0;
                    $skippedCount = 0;

                    foreach ($website->pages()->where('status', WebsitePageStatus::Draft)->get() as $page) {
                        if (empty($page->exported_html)) {
                            $skippedCount++;

                            continue;
                        }
                        $publishAction->execute($page);
                        $publishedCount++;
                    }

                    $website->update(['status' => WebsiteStatus::Published]);
                    $website->refresh();

                    return json_encode([
                        'success' => true,
                        'website_id' => $website->id,
                        'status' => $website->status->value,
                        'pages_published' => $publishedCount,
                        'pages_skipped_no_html' => $skippedCount,
                        'public_url' => url("/api/public/sites/{$website->slug}"),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function unpublishWebsite(): PrismToolObject
    {
        return PrismTool::as('unpublish_website')
            ->for('Unpublish a website. Takes the site offline and reverts all published pages to draft.')
            ->withStringParameter('website_id', 'The website UUID', required: true)
            ->using(function (string $website_id) {
                $website = Website::query()->find($website_id);
                if (! $website) {
                    return json_encode(['error' => 'Website not found']);
                }

                try {
                    $website = app(UnpublishWebsiteAction::class)->execute($website);

                    return json_encode([
                        'success' => true,
                        'website_id' => $website->id,
                        'status' => $website->status->value,
                        'message' => "Website '{$website->name}' unpublished.",
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function createWebsitePage(): PrismToolObject
    {
        return PrismTool::as('create_website_page')
            ->for('Create a new page on an existing website')
            ->withStringParameter('website_id', 'The parent website UUID', required: true)
            ->withStringParameter('title', 'Page title', required: true)
            ->withStringParameter('slug', 'URL slug (auto-generated from title if omitted)')
            ->withStringParameter('page_type', 'One of: page, post, product, landing (default: page)')
            ->using(function (string $website_id, string $title, ?string $slug = null, ?string $page_type = null) {
                $website = Website::query()->find($website_id);
                if (! $website) {
                    return json_encode(['error' => 'Website not found']);
                }

                $typeEnum = $page_type && WebsitePageType::tryFrom($page_type)
                    ? WebsitePageType::from($page_type)
                    : WebsitePageType::Page;

                try {
                    $page = app(CreateWebsitePageAction::class)->execute($website, [
                        'title' => $title,
                        'slug' => $slug ? Str::slug($slug) : Str::slug($title),
                        'page_type' => $typeEnum,
                    ]);

                    return json_encode([
                        'success' => true,
                        'page_id' => $page->id,
                        'website_id' => $page->website_id,
                        'title' => $page->title,
                        'slug' => $page->slug,
                        'page_type' => $page->page_type->value,
                        'status' => $page->status->value,
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function updateWebsitePage(): PrismToolObject
    {
        return PrismTool::as('update_website_page')
            ->for('Update a website page. Can change title, slug, page_type, the rendered HTML body, and the meta JSON (used for product price/sku/images on product pages).')
            ->withStringParameter('page_id', 'The page UUID', required: true)
            ->withStringParameter('title', 'New title')
            ->withStringParameter('slug', 'New slug')
            ->withStringParameter('page_type', 'New type: page, post, product, landing')
            ->withStringParameter('exported_html', 'Full HTML body of the page (passed through HtmlSanitizer; <script> tags are stripped — use external <script src> in the rendering shell instead).')
            ->withStringParameter('meta_json', 'JSON object merged into the page meta. Required keys for product pages: price (cents), currency, in_stock, sku, images, description, keywords.')
            ->using(function (string $page_id, ?string $title = null, ?string $slug = null, ?string $page_type = null, ?string $exported_html = null, ?string $meta_json = null) {
                $page = WebsitePage::query()->find($page_id);
                if (! $page) {
                    return json_encode(['error' => 'Website page not found']);
                }

                $data = [];
                if ($title !== null) {
                    $data['title'] = $title;
                }
                if ($slug !== null) {
                    $data['slug'] = Str::slug($slug);
                }
                if ($page_type !== null) {
                    $typeEnum = WebsitePageType::tryFrom($page_type);
                    if (! $typeEnum) {
                        return json_encode(['error' => "Invalid page_type '{$page_type}'. Must be one of: page, post, product, landing."]);
                    }
                    $data['page_type'] = $typeEnum;
                }
                if ($exported_html !== null) {
                    $data['exported_html'] = $exported_html;
                }
                if ($meta_json !== null) {
                    $decoded = json_decode($meta_json, true);
                    if (! is_array($decoded)) {
                        return json_encode(['error' => 'meta_json must be a JSON object.']);
                    }
                    $data['meta'] = array_merge((array) ($page->meta ?? []), $decoded);
                }

                if (empty($data)) {
                    return json_encode(['error' => 'No attributes provided to update']);
                }

                try {
                    $page = app(UpdateWebsitePageAction::class)->execute($page, $data);

                    return json_encode([
                        'success' => true,
                        'page_id' => $page->id,
                        'title' => $page->title,
                        'slug' => $page->slug,
                        'page_type' => $page->page_type->value,
                        'status' => $page->status->value,
                        'has_html' => ! empty($page->exported_html),
                        'meta_keys' => array_keys((array) ($page->meta ?? [])),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function publishWebsitePage(): PrismToolObject
    {
        return PrismTool::as('publish_website_page')
            ->for('Publish a single website page. The page must have exported HTML.')
            ->withStringParameter('page_id', 'The page UUID', required: true)
            ->using(function (string $page_id) {
                $page = WebsitePage::query()->find($page_id);
                if (! $page) {
                    return json_encode(['error' => 'Website page not found']);
                }

                try {
                    $page = app(PublishWebsitePageAction::class)->execute($page);

                    return json_encode([
                        'success' => true,
                        'page_id' => $page->id,
                        'status' => $page->status->value,
                        'published_at' => $page->published_at?->toIso8601String(),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function unpublishWebsitePage(): PrismToolObject
    {
        return PrismTool::as('unpublish_website_page')
            ->for('Unpublish a single website page. Reverts it to draft status and removes it from public serving.')
            ->withStringParameter('page_id', 'The page UUID', required: true)
            ->using(function (string $page_id) {
                $page = WebsitePage::query()->find($page_id);
                if (! $page) {
                    return json_encode(['error' => 'Website page not found']);
                }

                try {
                    $page = app(UnpublishWebsitePageAction::class)->execute($page);

                    return json_encode([
                        'success' => true,
                        'page_id' => $page->id,
                        'status' => $page->status->value,
                        'message' => "Page '{$page->title}' unpublished.",
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function deleteWebsite(): PrismToolObject
    {
        return PrismTool::as('delete_website')
            ->for('Permanently delete a website and all its pages, assets, and deployments. This is a destructive action.')
            ->withStringParameter('website_id', 'The website UUID', required: true)
            ->using(function (string $website_id) {
                $website = Website::query()->find($website_id);
                if (! $website) {
                    return json_encode(['error' => 'Website not found']);
                }

                $name = $website->name;

                try {
                    app(DeleteWebsiteAction::class)->execute($website);

                    return json_encode(['success' => true, 'message' => "Website '{$name}' deleted."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function deleteWebsitePage(): PrismToolObject
    {
        return PrismTool::as('delete_website_page')
            ->for('Permanently delete a single website page. This is a destructive action.')
            ->withStringParameter('page_id', 'The page UUID', required: true)
            ->using(function (string $page_id) {
                $page = WebsitePage::query()->find($page_id);
                if (! $page) {
                    return json_encode(['error' => 'Website page not found']);
                }

                $title = $page->title;

                try {
                    app(DeleteWebsitePageAction::class)->execute($page);

                    return json_encode(['success' => true, 'message' => "Page '{$title}' deleted."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }
}
