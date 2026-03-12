<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\DTOs\NavigationItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Registry for plugin-contributed sidebar navigation items.
 *
 * Bound as a singleton in AppServiceProvider.
 * Rendered in resources/views/components/sidebar.blade.php after the core links.
 */
class NavigationRegistry
{
    /** @var list<NavigationItem> */
    protected array $items = [];

    public function add(NavigationItem $item): void
    {
        $this->items[] = $item;
    }

    /**
     * Return all items visible to the current user, sorted by order.
     *
     * @return Collection<int, NavigationItem>
     */
    public function items(): Collection
    {
        return collect($this->items)
            ->sortBy('order')
            ->values()
            ->filter(fn (NavigationItem $item) => $item->permission === null || Gate::allows($item->permission));
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }
}
