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
        'tools',
        'toolsets',
        'credentials',
        'crews',
        'workflows',
        'projects',
        'approvals',
        'evaluation',
        'signals',
        'triggers',
        'outbound',
        'notifications',
        'marketplace',
        'assistant',
        'chatbots',
        'voice-agents',
        'email',
        'websites',
        'memory',
        'knowledge-graph',
        'integrations',
        'evolution',
        'metrics',
        'compute',
        'api-reference',
        'mcp-server',
        'webhooks',
        'git-repos',
        'plugins',
        'security',
        'budget',
        'audit-log',
        'changelog',
    ];

    public function show(string $page = 'introduction'): Response
    {
        abort_unless(in_array($page, $this->pages, true), 404);

        $view = 'docs.'.$page;

        abort_unless(view()->exists($view), 404);

        return response()->view($view);
    }
}
