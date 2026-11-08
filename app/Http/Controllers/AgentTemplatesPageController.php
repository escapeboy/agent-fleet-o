<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Serves the public agent template gallery (GET /templates and
 * /templates/{slug}). Content lives in config/agent-templates.php.
 */
class AgentTemplatesPageController extends Controller
{
    public function index(): View
    {
        $templates = self::templates();
        $categories = $templates->groupBy('category');

        return view('templates.index', compact('templates', 'categories'));
    }

    public function show(string $slug): View
    {
        $template = self::templates()->firstWhere('slug', $slug);
        abort_if(! $template, 404);

        return view('templates.show', compact('template'));
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return self::templates()->pluck('slug')->values()->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function templates(): Collection
    {
        return collect(config('agent-templates', []));
    }
}
