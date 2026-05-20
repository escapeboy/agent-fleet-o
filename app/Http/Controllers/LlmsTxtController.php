<?php

namespace App\Http\Controllers;

use App\Mcp\Servers\AgentFleetServer;
use Illuminate\Http\Response;
use ReflectionClass;

/**
 * Serves /llms.txt and /llms-full.txt per the llmstxt.org spec.
 *
 * Discoverability surface for coding agents (Claude, Cursor, Codex). The
 * compact `/llms.txt` is the index — short, links-only. The full
 * `/llms-full.txt` concatenates the platform capabilities document so an
 * agent can pull the entire knowledge surface in one fetch.
 *
 * Both endpoints are public, unauthenticated, and rate-limited via route
 * middleware. Served as `text/markdown` (the llmstxt.org spec accepts
 * markdown; tooling tends to treat it as plain text either way).
 */
class LlmsTxtController extends Controller
{
    public function compact(): Response
    {
        return $this->markdown($this->buildCompact());
    }

    public function full(): Response
    {
        return $this->markdown($this->buildFull());
    }

    private function buildCompact(): string
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $toolCount = $this->countMcpTools();

        return <<<MARKDOWN
        # FleetQ

        > FleetQ is an AI agent mission-control platform. It runs autonomous
        > agents, multi-agent crews, and visual workflows against inbound
        > signals, with human-in-the-loop approvals, budget enforcement, and
        > a full audit trail. Every platform capability is exposed to agents
        > over the Model Context Protocol (MCP) — anything a human can do in
        > the UI, an agent can do too.

        ## For agents

        FleetQ ships a Model Context Protocol server. Point any MCP client
        (Claude, Cursor, Codex, or your own agent) at it and you get native
        tools for the whole platform — no custom integration code.

        - MCP endpoint (HTTP/SSE): `POST {$baseUrl}/mcp` — authenticate with a
          Sanctum bearer token.
        - MCP endpoint (local stdio): `php artisan mcp:start agent-fleet`.
        - {$toolCount}+ MCP tools across agents, crews, experiments,
          workflows, signals, outbound delivery, approvals, budget, memory,
          and marketplace.
        - Discovery document: [{$baseUrl}/.well-known/fleetq]({$baseUrl}/.well-known/fleetq)
        - Full agent-readable docs: [{$baseUrl}/llms-full.txt]({$baseUrl}/llms-full.txt)

        ## Capabilities

        - Agents — create, configure, and run autonomous AI agents
          (role/goal/backstory, skills, tools, provider BYOK).
        - Crews — multi-agent teams with coordinator/QA roles and consensus
          patterns.
        - Experiments — a 20-state pipeline from signal to delivered outcome.
        - Workflows — visual DAG builder; agent, conditional, switch, and
          human-task nodes.
        - Signals — 30+ inbound connectors (webhooks, RSS, IMAP, GitHub,
          Slack, Sentry…).
        - Outbound — email (SMTP or Resend), webhooks, and chat-channel
          delivery.
        - Audiences — team-scoped contact lists with subscription topics and
          unsubscribe handling, fed by delivery webhooks.
        - Approvals — human-in-the-loop gates and human tasks with SLA
          escalation.
        - Budget — credit ledger, cost forecasting, and kill switches.

        ## Links

        - API documentation (OpenAPI 3.1): [{$baseUrl}/docs/api]({$baseUrl}/docs/api)
        - OpenAPI spec (JSON): [{$baseUrl}/api/v1/openapi.json]({$baseUrl}/api/v1/openapi.json)
        - Integrations directory: [{$baseUrl}/integrations/explore]({$baseUrl}/integrations/explore)

        MARKDOWN;
    }

    private function buildFull(): string
    {
        $sections = [trim($this->buildCompact())];

        $capabilities = $this->readCapabilities();

        if ($capabilities !== null) {
            $sections[] = "---\n\n".trim($capabilities);
        }

        return implode("\n\n", $sections)."\n";
    }

    private function readCapabilities(): ?string
    {
        $path = base_path('docs/capabilities.md');

        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    private function markdown(string $body): Response
    {
        return response($body, 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'all',
        ]);
    }

    private function countMcpTools(): int
    {
        try {
            $reflection = new ReflectionClass(AgentFleetServer::class);
            $defaults = $reflection->getDefaultProperties();
            $tools = $defaults['tools'] ?? [];

            return is_array($tools) ? count($tools) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
