<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class MarketplaceQueryService
{
    public function browse(
        ?string $search = null,
        ?string $type = null,
        ?string $category = null,
        string $sort = '-install_count',
        int $perPage = 12,
        ?string $teamId = null,
    ): LengthAwarePaginator {
        $query = MarketplaceListing::query()
            ->where('status', MarketplaceStatus::Published)
            ->where(function ($q) use ($teamId) {
                $q->where('visibility', ListingVisibility::Public);
                if ($teamId) {
                    $q->orWhere(fn ($q2) => $q2
                        ->where('visibility', ListingVisibility::Team)
                        ->where('team_id', $teamId),
                    );
                }
            });

        if ($search) {
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('description', 'ilike', "%{$search}%"));
        }

        if ($type) {
            $query->where('type', $type);
        }

        if ($category) {
            $query->where('category', $category);
        }

        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $allowedSorts = ['install_count', 'avg_rating', 'created_at', 'name'];

        if (in_array($column, $allowedSorts)) {
            $query->orderBy($column, $direction);
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function categories(?string $teamId = null): Collection
    {
        return MarketplaceListing::query()
            ->where('status', MarketplaceStatus::Published)
            ->where(function ($q) use ($teamId) {
                $q->where('visibility', ListingVisibility::Public);
                if ($teamId) {
                    $q->orWhere(fn ($q2) => $q2
                        ->where('visibility', ListingVisibility::Team)
                        ->where('team_id', $teamId),
                    );
                }
            })
            ->whereNotNull('category')
            ->select('category')
            ->selectRaw('count(*) as count')
            ->groupBy('category')
            ->orderByDesc('count')
            ->get();
    }
}
