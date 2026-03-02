<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Serves individual use-case landing pages.
 * Used by GET /use-cases/{slug} in routes/web.php.
 * Separated from a closure so routes can be cached (route:cache).
 */
class UseCasesController
{
    public function __invoke(string $slug): View
    {
        $uc = config("use_cases.{$slug}");
        abort_if(! $uc, 404);

        return view('use-cases.show', compact('slug', 'uc'));
    }
}
