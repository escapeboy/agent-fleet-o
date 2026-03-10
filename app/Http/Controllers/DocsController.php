<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class DocsController extends Controller
{
    private array $pages = [
        'introduction',
        'getting-started',
        'experiments',
        'agents',
        'skills',
        'crews',
        'workflows',
        'projects',
        'signals',
        'triggers',
        'marketplace',
        'assistant',
        'api-reference',
        'mcp-server',
        'security',
        'budget',
        'audit-log',
        'changelog',
    ];

    public function show(string $page = 'introduction'): Response
    {
        abort_unless(in_array($page, $this->pages, true), 404);

        $view = 'docs.' . $page;

        abort_unless(view()->exists($view), 404);

        return response()->view($view);
    }
}
