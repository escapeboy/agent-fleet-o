<?php

namespace App\Http\Controllers;

use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Services\MarketplaceQueryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketplacePageController extends Controller
{
    public function __construct(private MarketplaceQueryService $queryService) {}

    public function index(Request $request): View
    {
        $listings = $this->queryService->browse(
            search: $request->query('search'),
            type: $request->query('type'),
            category: $request->query('category'),
            sort: $request->query('sort', '-install_count'),
            perPage: 12,
        );

        $categories = $this->queryService->categories();

        return view('marketplace.index', compact('listings', 'categories'));
    }

    public function show(MarketplaceListing $listing): View
    {
        abort_unless($listing->isPublished(), 404);
        $listing->load('reviews.user', 'publisher');

        return view('marketplace.show', compact('listing'));
    }

    public function category(string $category, Request $request): View
    {
        $listings = $this->queryService->browse(
            category: $category,
            search: $request->query('search'),
            sort: $request->query('sort', '-install_count'),
            perPage: 12,
        );

        $categories = $this->queryService->categories();

        return view('marketplace.category', compact('listings', 'category', 'categories'));
    }
}
