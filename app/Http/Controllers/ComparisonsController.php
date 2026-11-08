<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Serves the public comparison pages (GET /compare/{slug}).
 * Content lives in config/comparisons.php, keyed by slug.
 * Separated from a closure so routes can be cached (route:cache).
 */
class ComparisonsController
{
    public function __invoke(string $slug): View
    {
        $c = config("comparisons.{$slug}");
        abort_if(! is_array($c), 404);

        return view('compare.show', [
            'slug' => $slug,
            'c' => $c,
            'checkedOn' => config('comparisons.checked_on'),
        ]);
    }
}
