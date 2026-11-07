<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Serves individual comparison pages (FleetQ vs another tool).
 * Used by GET /compare/{slug} in routes/web.php.
 * Separated from a closure so routes can be cached (route:cache).
 */
class ComparisonsController
{
    public function __invoke(string $slug): View
    {
        $comparison = self::pages()[$slug] ?? null;
        abort_if($comparison === null, 404);

        return view('compare.show', [
            'slug' => $slug,
            'comparison' => $comparison,
            'checkedOn' => config('comparisons.checked_on'),
        ]);
    }

    /**
     * Comparison pages keyed by slug, without the shared 'checked_on' date.
     * Single source for the route, the /compare index and the sitemap.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function pages(): array
    {
        return collect(config('comparisons', []))
            ->except('checked_on')
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->all();
    }
}
