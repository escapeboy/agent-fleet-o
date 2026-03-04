<?php

namespace Database\Seeders;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Enums\ToolRiskLevel;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Cloud\Providers\CloudServiceProvider;
use Illuminate\Database\Seeder;

class PopularToolsSeeder extends Seeder
{
    public function run(): void
    {
        // Cloud mode: create as platform tools (team_id = null, is_platform = true)
        // Community mode: create as team tools for the default team
        $isPlatform = class_exists(CloudServiceProvider::class);

        $team = $isPlatform ? null : Team::first();

        if (! $isPlatform && ! $team) {
            $this->command?->warn('No team found. Run app:install first.');

            return;
        }

        $definitions = $this->toolDefinitions();
        $created = 0;
        $skipped = 0;

        foreach ($definitions as $def) {
            $matchKey = $isPlatform
                ? ['slug' => $def['slug'], 'is_platform' => true]
                : ['team_id' => $team->id, 'slug' => $def['slug']];

            $tool = Tool::withoutGlobalScopes()->updateOrCreate(
                $matchKey,
                [
                    'team_id' => $team?->id,
                    'is_platform' => $isPlatform,
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'type' => $def['type'],
                    'status' => ToolStatus::Disabled,
                    'risk_level' => $def['risk_level'],
                    'transport_config' => $def['transport_config'],
                    'tool_definitions' => $def['tool_definitions'],
                    'settings' => $def['settings'],
                ],
            );

            if ($tool->wasRecentlyCreated) {
                $created++;
            } else {
                $skipped++;
            }
        }

        $mode = $isPlatform ? 'platform' : 'team';
        $this->command?->info("Tools ({$mode}): {$created} created, {$skipped} already existed.");
    }

    protected function toolDefinitions(): array
    {
        return [
            // ─── Built-in Tools ─────────────────────────────────────

            [
                'name' => 'Bash Shell',
                'slug' => 'bash-shell',
                'description' => 'Execute shell commands in a sandboxed environment. Supports common CLI tools like curl, jq, python3, node, grep, awk, sed, and more. Commands are restricted to an allowed list and paths are sandboxed.',
                'type' => ToolType::BuiltIn,
                'risk_level' => ToolRiskLevel::Destructive,
                'transport_config' => [
                    'kind' => 'bash',
                    'allowed_commands' => ['curl', 'jq', 'python3', 'node', 'grep', 'awk', 'sed', 'cat', 'echo', 'ls', 'find', 'wc', 'head', 'tail', 'sort', 'uniq', 'wget', 'tar', 'gzip', 'gunzip'],
                    'allowed_paths' => ['/tmp/agent-workspace'],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'bash_execute',
                        'description' => 'Execute a shell command and return stdout/stderr',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'command' => ['type' => 'string', 'description' => 'Shell command to execute'],
                                'working_directory' => ['type' => 'string', 'description' => 'Working directory (must be within allowed paths)'],
                            ],
                            'required' => ['command'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 30],
            ],

            [
                'name' => 'Filesystem',
                'slug' => 'filesystem',
                'description' => 'Read, write, and list files in allowed directories. Useful for agents that need to create or modify files as part of their work.',
                'type' => ToolType::BuiltIn,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'kind' => 'filesystem',
                    'allowed_paths' => ['/tmp/agent-workspace'],
                    'read_only' => false,
                ],
                'tool_definitions' => [
                    [
                        'name' => 'file_read',
                        'description' => 'Read the contents of a file',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'description' => 'Path to the file to read'],
                            ],
                            'required' => ['path'],
                        ],
                    ],
                    [
                        'name' => 'file_write',
                        'description' => 'Write content to a file',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'description' => 'Path to write to'],
                                'content' => ['type' => 'string', 'description' => 'Content to write'],
                            ],
                            'required' => ['path', 'content'],
                        ],
                    ],
                    [
                        'name' => 'file_list',
                        'description' => 'List directory contents',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'description' => 'Directory path to list'],
                            ],
                            'required' => ['path'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 10],
            ],

            // ─── MCP Stdio Tools ────────────────────────────────────

            [
                'name' => 'Web Fetch',
                'slug' => 'web-fetch',
                'description' => 'Fetch and read web pages, converting HTML to markdown. Useful for agents that need to retrieve information from URLs, read documentation, or scrape web content.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Read,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@anthropic-ai/mcp-server-fetch'],
                    'env' => [],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'fetch',
                        'description' => 'Fetch a URL and return its contents as markdown',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'url' => ['type' => 'string', 'description' => 'URL to fetch'],
                                'max_length' => ['type' => 'integer', 'description' => 'Maximum content length (default: 5000)'],
                                'start_index' => ['type' => 'integer', 'description' => 'Start index for pagination'],
                                'raw' => ['type' => 'boolean', 'description' => 'Return raw content without markdown conversion'],
                            ],
                            'required' => ['url'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 30],
            ],

            [
                'name' => 'Brave Search',
                'slug' => 'brave-search',
                'description' => 'Search the web using Brave Search API. Returns relevant web results with titles, URLs, and descriptions. Requires a Brave Search API key (free tier available).',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Safe,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@anthropic-ai/mcp-server-brave-search'],
                    'env' => ['BRAVE_API_KEY' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'brave_web_search',
                        'description' => 'Search the web using Brave Search',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Search query'],
                                'count' => ['type' => 'integer', 'description' => 'Number of results (default: 10, max: 20)'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'brave_local_search',
                        'description' => 'Search for local businesses and places',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Local search query'],
                                'count' => ['type' => 'integer', 'description' => 'Number of results (default: 5)'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            [
                'name' => 'GitHub',
                'slug' => 'github',
                'description' => 'Interact with GitHub repositories — create/read issues, pull requests, browse files, search code, and manage repos. Requires a GitHub personal access token.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@modelcontextprotocol/server-github'],
                    'env' => ['GITHUB_PERSONAL_ACCESS_TOKEN' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'create_or_update_file',
                        'description' => 'Create or update a file in a GitHub repository',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'owner' => ['type' => 'string'],
                                'repo' => ['type' => 'string'],
                                'path' => ['type' => 'string'],
                                'content' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                                'branch' => ['type' => 'string'],
                            ],
                            'required' => ['owner', 'repo', 'path', 'content', 'message', 'branch'],
                        ],
                    ],
                    [
                        'name' => 'search_repositories',
                        'description' => 'Search for GitHub repositories',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Search query'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'create_issue',
                        'description' => 'Create a new issue in a repository',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'owner' => ['type' => 'string'],
                                'repo' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'body' => ['type' => 'string'],
                            ],
                            'required' => ['owner', 'repo', 'title'],
                        ],
                    ],
                    [
                        'name' => 'create_pull_request',
                        'description' => 'Create a new pull request',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'owner' => ['type' => 'string'],
                                'repo' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'body' => ['type' => 'string'],
                                'head' => ['type' => 'string'],
                                'base' => ['type' => 'string'],
                            ],
                            'required' => ['owner', 'repo', 'title', 'head', 'base'],
                        ],
                    ],
                    [
                        'name' => 'get_file_contents',
                        'description' => 'Get file or directory contents from a repository',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'owner' => ['type' => 'string'],
                                'repo' => ['type' => 'string'],
                                'path' => ['type' => 'string'],
                                'branch' => ['type' => 'string'],
                            ],
                            'required' => ['owner', 'repo', 'path'],
                        ],
                    ],
                    [
                        'name' => 'search_code',
                        'description' => 'Search for code across GitHub repositories',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'q' => ['type' => 'string', 'description' => 'Search query with GitHub code search syntax'],
                            ],
                            'required' => ['q'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 30],
            ],

            [
                'name' => 'Memory',
                'slug' => 'memory',
                'description' => 'Persistent key-value memory for agents. Store and retrieve information across conversations. Uses a knowledge graph to relate entities and observations.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@modelcontextprotocol/server-memory'],
                    'env' => [],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'create_entities',
                        'description' => 'Create new entities in the knowledge graph',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'entities' => ['type' => 'array', 'description' => 'Array of entities to create'],
                            ],
                            'required' => ['entities'],
                        ],
                    ],
                    [
                        'name' => 'create_relations',
                        'description' => 'Create relations between entities',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'relations' => ['type' => 'array', 'description' => 'Array of relations to create'],
                            ],
                            'required' => ['relations'],
                        ],
                    ],
                    [
                        'name' => 'add_observations',
                        'description' => 'Add observations to existing entities',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'observations' => ['type' => 'array', 'description' => 'Array of observations'],
                            ],
                            'required' => ['observations'],
                        ],
                    ],
                    [
                        'name' => 'search_nodes',
                        'description' => 'Search for entities by query',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Search query'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'read_graph',
                        'description' => 'Read the entire knowledge graph',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 10],
            ],

            [
                'name' => 'Puppeteer',
                'slug' => 'puppeteer',
                'description' => 'Browser automation — navigate pages, take screenshots, click elements, fill forms, and execute JavaScript. Runs a headless Chromium browser for web scraping and testing.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@anthropic-ai/mcp-server-puppeteer'],
                    'env' => [],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'puppeteer_navigate',
                        'description' => 'Navigate to a URL in the browser',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'url' => ['type' => 'string', 'description' => 'URL to navigate to'],
                            ],
                            'required' => ['url'],
                        ],
                    ],
                    [
                        'name' => 'puppeteer_screenshot',
                        'description' => 'Take a screenshot of the current page or element',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'description' => 'Name for the screenshot'],
                                'selector' => ['type' => 'string', 'description' => 'CSS selector to screenshot (optional)'],
                                'width' => ['type' => 'integer', 'description' => 'Viewport width'],
                                'height' => ['type' => 'integer', 'description' => 'Viewport height'],
                            ],
                            'required' => ['name'],
                        ],
                    ],
                    [
                        'name' => 'puppeteer_click',
                        'description' => 'Click an element on the page',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'selector' => ['type' => 'string', 'description' => 'CSS selector of element to click'],
                            ],
                            'required' => ['selector'],
                        ],
                    ],
                    [
                        'name' => 'puppeteer_fill',
                        'description' => 'Fill in a form field',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'selector' => ['type' => 'string', 'description' => 'CSS selector of the input field'],
                                'value' => ['type' => 'string', 'description' => 'Value to fill'],
                            ],
                            'required' => ['selector', 'value'],
                        ],
                    ],
                    [
                        'name' => 'puppeteer_evaluate',
                        'description' => 'Execute JavaScript in the browser context',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'script' => ['type' => 'string', 'description' => 'JavaScript code to execute'],
                            ],
                            'required' => ['script'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 60],
            ],

            [
                'name' => 'Slack',
                'slug' => 'slack',
                'description' => 'Send messages, list channels, and interact with Slack workspaces. Requires a Slack Bot token with appropriate scopes.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@modelcontextprotocol/server-slack'],
                    'env' => ['SLACK_BOT_TOKEN' => '', 'SLACK_TEAM_ID' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'slack_list_channels',
                        'description' => 'List available Slack channels',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'limit' => ['type' => 'integer', 'description' => 'Max channels to return'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'slack_post_message',
                        'description' => 'Post a message to a Slack channel',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'channel_id' => ['type' => 'string', 'description' => 'Channel ID to post to'],
                                'text' => ['type' => 'string', 'description' => 'Message text'],
                            ],
                            'required' => ['channel_id', 'text'],
                        ],
                    ],
                    [
                        'name' => 'slack_get_channel_history',
                        'description' => 'Get recent messages from a channel',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'channel_id' => ['type' => 'string', 'description' => 'Channel ID'],
                                'limit' => ['type' => 'integer', 'description' => 'Number of messages'],
                            ],
                            'required' => ['channel_id'],
                        ],
                    ],
                    [
                        'name' => 'slack_reply_to_thread',
                        'description' => 'Reply to a specific message thread',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'channel_id' => ['type' => 'string', 'description' => 'Channel ID'],
                                'thread_ts' => ['type' => 'string', 'description' => 'Thread timestamp'],
                                'text' => ['type' => 'string', 'description' => 'Reply text'],
                            ],
                            'required' => ['channel_id', 'thread_ts', 'text'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            [
                'name' => 'Google Maps',
                'slug' => 'google-maps',
                'description' => 'Search for places, get directions, geocode addresses, and retrieve place details using the Google Maps API. Requires a Google Maps API key.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Safe,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@modelcontextprotocol/server-google-maps'],
                    'env' => ['GOOGLE_MAPS_API_KEY' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'maps_geocode',
                        'description' => 'Geocode an address to coordinates',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'address' => ['type' => 'string', 'description' => 'Address to geocode'],
                            ],
                            'required' => ['address'],
                        ],
                    ],
                    [
                        'name' => 'maps_reverse_geocode',
                        'description' => 'Reverse geocode coordinates to an address',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'latitude' => ['type' => 'number'],
                                'longitude' => ['type' => 'number'],
                            ],
                            'required' => ['latitude', 'longitude'],
                        ],
                    ],
                    [
                        'name' => 'maps_search_places',
                        'description' => 'Search for places near a location',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Search query'],
                                'location' => ['type' => 'string', 'description' => 'Location (lat,lng)'],
                                'radius' => ['type' => 'integer', 'description' => 'Search radius in meters'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'maps_directions',
                        'description' => 'Get directions between two points',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'origin' => ['type' => 'string', 'description' => 'Starting point'],
                                'destination' => ['type' => 'string', 'description' => 'End point'],
                                'mode' => ['type' => 'string', 'description' => 'Travel mode: driving, walking, bicycling, transit'],
                            ],
                            'required' => ['origin', 'destination'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            [
                'name' => 'PostgreSQL',
                'slug' => 'postgresql',
                'description' => 'Execute read-only SQL queries against a PostgreSQL database. Useful for data analysis, reporting, and exploration. Connection string required.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Read,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@modelcontextprotocol/server-postgres', ''],
                    'env' => [],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'query',
                        'description' => 'Execute a read-only SQL query',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'sql' => ['type' => 'string', 'description' => 'SQL query to execute (read-only)'],
                            ],
                            'required' => ['sql'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 30],
            ],

            [
                'name' => 'Sequential Thinking',
                'slug' => 'sequential-thinking',
                'description' => 'A tool for step-by-step reasoning and problem-solving. Helps agents think through complex problems by breaking them into sequential thoughts with revision capabilities.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Safe,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@modelcontextprotocol/server-sequential-thinking'],
                    'env' => [],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'sequentialthinking',
                        'description' => 'Record a sequential thinking step',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'thought' => ['type' => 'string', 'description' => 'Current thinking step'],
                                'nextThoughtNeeded' => ['type' => 'boolean', 'description' => 'Whether more thinking is needed'],
                                'thoughtNumber' => ['type' => 'integer', 'description' => 'Current thought number'],
                                'totalThoughts' => ['type' => 'integer', 'description' => 'Estimated total thoughts needed'],
                                'isRevision' => ['type' => 'boolean', 'description' => 'Whether this revises a previous thought'],
                                'revisesThought' => ['type' => 'integer', 'description' => 'Which thought this revises'],
                            ],
                            'required' => ['thought', 'nextThoughtNeeded', 'thoughtNumber', 'totalThoughts'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 10],
            ],

            [
                'name' => 'Exa Search',
                'slug' => 'exa-search',
                'description' => 'AI-powered web search using Exa. Returns high-quality, semantically relevant results with full page content. Ideal for research tasks. Requires an Exa API key.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Safe,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@anthropic-ai/mcp-server-exa'],
                    'env' => ['EXA_API_KEY' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'web_search_exa',
                        'description' => 'Search the web using Exa AI search',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Search query'],
                                'numResults' => ['type' => 'integer', 'description' => 'Number of results (default: 10)'],
                                'type' => ['type' => 'string', 'description' => 'Search type: neural, keyword, auto'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'get_page_contents',
                        'description' => 'Get full contents of web pages by URL',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'urls' => ['type' => 'array', 'description' => 'URLs to retrieve content from'],
                            ],
                            'required' => ['urls'],
                        ],
                    ],
                    [
                        'name' => 'find_similar',
                        'description' => 'Find pages similar to a given URL',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'url' => ['type' => 'string', 'description' => 'URL to find similar pages for'],
                                'numResults' => ['type' => 'integer', 'description' => 'Number of results'],
                            ],
                            'required' => ['url'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 20],
            ],

            [
                'name' => 'Filesystem (MCP)',
                'slug' => 'filesystem-mcp',
                'description' => 'Full filesystem access via MCP — read, write, move, search, and get file info. More capable than the built-in filesystem tool, with directory tree visualization and file search.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/tmp/agent-workspace'],
                    'env' => [],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'read_file',
                        'description' => 'Read the contents of a file',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'description' => 'Path to file'],
                            ],
                            'required' => ['path'],
                        ],
                    ],
                    [
                        'name' => 'write_file',
                        'description' => 'Write content to a file (creates or overwrites)',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'description' => 'Path to file'],
                                'content' => ['type' => 'string', 'description' => 'Content to write'],
                            ],
                            'required' => ['path', 'content'],
                        ],
                    ],
                    [
                        'name' => 'list_directory',
                        'description' => 'List directory contents with type indicators',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'description' => 'Directory path'],
                            ],
                            'required' => ['path'],
                        ],
                    ],
                    [
                        'name' => 'search_files',
                        'description' => 'Recursively search for files matching a pattern',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'description' => 'Starting directory'],
                                'pattern' => ['type' => 'string', 'description' => 'Search pattern (glob)'],
                            ],
                            'required' => ['path', 'pattern'],
                        ],
                    ],
                    [
                        'name' => 'move_file',
                        'description' => 'Move or rename a file/directory',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'source' => ['type' => 'string', 'description' => 'Source path'],
                                'destination' => ['type' => 'string', 'description' => 'Destination path'],
                            ],
                            'required' => ['source', 'destination'],
                        ],
                    ],
                    [
                        'name' => 'directory_tree',
                        'description' => 'Get a visual tree representation of a directory',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'description' => 'Directory path'],
                            ],
                            'required' => ['path'],
                        ],
                    ],
                    [
                        'name' => 'get_file_info',
                        'description' => 'Get metadata about a file (size, modified time, permissions)',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'description' => 'File path'],
                            ],
                            'required' => ['path'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            [
                'name' => 'Sentry',
                'slug' => 'sentry',
                'description' => 'Monitor and manage application errors via Sentry. List issues, get error details, and track error trends. Requires a Sentry auth token.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Read,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@sentry/mcp-server'],
                    'env' => ['SENTRY_AUTH_TOKEN' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'list_issues',
                        'description' => 'List recent Sentry issues',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'organization_slug' => ['type' => 'string'],
                                'project_slug' => ['type' => 'string'],
                                'query' => ['type' => 'string', 'description' => 'Search query'],
                            ],
                            'required' => ['organization_slug', 'project_slug'],
                        ],
                    ],
                    [
                        'name' => 'get_issue_details',
                        'description' => 'Get detailed information about a Sentry issue',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'issue_id' => ['type' => 'string', 'description' => 'Sentry issue ID'],
                            ],
                            'required' => ['issue_id'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            [
                'name' => 'Notion',
                'slug' => 'notion',
                'description' => 'Read and write Notion pages and databases. Search content, create pages, update properties, and manage databases. Requires a Notion integration token.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@notionhq/notion-mcp-server'],
                    'env' => ['OPENAPI_MCP_HEADERS' => '{"Authorization": "Bearer YOUR_NOTION_TOKEN", "Notion-Version": "2022-06-28"}'],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'notion_search',
                        'description' => 'Search across Notion pages and databases',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Search query'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'notion_create_page',
                        'description' => 'Create a new Notion page',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'parent_id' => ['type' => 'string', 'description' => 'Parent page or database ID'],
                                'title' => ['type' => 'string', 'description' => 'Page title'],
                                'content' => ['type' => 'string', 'description' => 'Page content in markdown'],
                            ],
                            'required' => ['parent_id', 'title'],
                        ],
                    ],
                    [
                        'name' => 'notion_read_page',
                        'description' => 'Read a Notion page by ID',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'page_id' => ['type' => 'string', 'description' => 'Page ID to read'],
                            ],
                            'required' => ['page_id'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            [
                'name' => 'Linear',
                'slug' => 'linear',
                'description' => 'Manage Linear issues, projects, and cycles. Create and update issues, search across projects, and track progress. Requires a Linear API key.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@linear/mcp-server'],
                    'env' => ['LINEAR_API_KEY' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'linear_create_issue',
                        'description' => 'Create a new Linear issue',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string', 'description' => 'Issue title'],
                                'description' => ['type' => 'string', 'description' => 'Issue description in markdown'],
                                'teamId' => ['type' => 'string', 'description' => 'Team ID'],
                                'priority' => ['type' => 'integer', 'description' => 'Priority (0=none, 1=urgent, 2=high, 3=medium, 4=low)'],
                            ],
                            'required' => ['title', 'teamId'],
                        ],
                    ],
                    [
                        'name' => 'linear_search_issues',
                        'description' => 'Search Linear issues',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Search query'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'linear_update_issue',
                        'description' => 'Update an existing Linear issue',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'issueId' => ['type' => 'string', 'description' => 'Issue ID to update'],
                                'title' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'stateId' => ['type' => 'string', 'description' => 'New state ID'],
                            ],
                            'required' => ['issueId'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            // ─── New MCP Tools (Phase 3) ──────────────────────────────

            [
                'name' => 'Playwright',
                'slug' => 'playwright',
                'description' => 'Browser automation with Playwright — navigate pages, take screenshots, click elements, fill forms, and run accessibility snapshots. More reliable than Puppeteer with auto-waiting and multi-browser support. No API key required.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@playwright/mcp@latest'],
                    'env' => [],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'browser_navigate',
                        'description' => 'Navigate to a URL in the browser',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'url' => ['type' => 'string', 'description' => 'URL to navigate to'],
                            ],
                            'required' => ['url'],
                        ],
                    ],
                    [
                        'name' => 'browser_screenshot',
                        'description' => 'Take a screenshot of the current page',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'description' => 'Name for the screenshot'],
                                'selector' => ['type' => 'string', 'description' => 'CSS selector to screenshot (optional)'],
                                'fullPage' => ['type' => 'boolean', 'description' => 'Capture full scrollable page'],
                            ],
                            'required' => ['name'],
                        ],
                    ],
                    [
                        'name' => 'browser_click',
                        'description' => 'Click an element on the page using accessibility ref',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'element' => ['type' => 'string', 'description' => 'Human-readable element description'],
                                'ref' => ['type' => 'string', 'description' => 'Element reference from page snapshot'],
                            ],
                            'required' => ['element', 'ref'],
                        ],
                    ],
                    [
                        'name' => 'browser_snapshot',
                        'description' => 'Capture an accessibility snapshot of the current page',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                    [
                        'name' => 'browser_type',
                        'description' => 'Type text into an editable element',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'ref' => ['type' => 'string', 'description' => 'Element reference'],
                                'text' => ['type' => 'string', 'description' => 'Text to type'],
                                'submit' => ['type' => 'boolean', 'description' => 'Press Enter after typing'],
                            ],
                            'required' => ['ref', 'text'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 60],
            ],

            [
                'name' => 'Context7',
                'slug' => 'context7',
                'description' => 'Retrieve up-to-date documentation and code examples for any library or framework directly from source. Resolves library IDs and queries official docs. No API key required.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Safe,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@upstash/context7-mcp@latest'],
                    'env' => [],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'resolve-library-id',
                        'description' => 'Resolve a package name to a Context7 library ID',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'libraryName' => ['type' => 'string', 'description' => 'Library name to search for'],
                            ],
                            'required' => ['libraryName'],
                        ],
                    ],
                    [
                        'name' => 'get-library-docs',
                        'description' => 'Query documentation for a specific library',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'context7CompatibleLibraryID' => ['type' => 'string', 'description' => 'Library ID from resolve-library-id'],
                                'topic' => ['type' => 'string', 'description' => 'Topic or question to search for'],
                            ],
                            'required' => ['context7CompatibleLibraryID'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 20],
            ],

            [
                'name' => 'Firecrawl',
                'slug' => 'firecrawl',
                'description' => 'Advanced web scraping and crawling — extract structured data from websites, crawl entire sites, and convert pages to clean markdown. Supports JavaScript rendering. Requires a Firecrawl API key.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Read,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', 'firecrawl-mcp'],
                    'env' => ['FIRECRAWL_API_KEY' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'firecrawl_scrape',
                        'description' => 'Scrape a single URL and return clean markdown content',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'url' => ['type' => 'string', 'description' => 'URL to scrape'],
                                'formats' => ['type' => 'array', 'description' => 'Output formats: markdown, html, rawHtml'],
                            ],
                            'required' => ['url'],
                        ],
                    ],
                    [
                        'name' => 'firecrawl_crawl',
                        'description' => 'Crawl an entire website from a starting URL',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'url' => ['type' => 'string', 'description' => 'Starting URL to crawl'],
                                'limit' => ['type' => 'integer', 'description' => 'Maximum pages to crawl (default: 50)'],
                            ],
                            'required' => ['url'],
                        ],
                    ],
                    [
                        'name' => 'firecrawl_extract',
                        'description' => 'Extract structured data from a URL using a schema',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'urls' => ['type' => 'array', 'description' => 'URLs to extract data from'],
                                'prompt' => ['type' => 'string', 'description' => 'Extraction prompt describing what data to extract'],
                            ],
                            'required' => ['urls'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 60],
            ],

            [
                'name' => 'YouTube Transcript',
                'slug' => 'youtube-transcript',
                'description' => 'Extract transcripts from YouTube videos. Useful for summarizing video content, extracting key information, or processing educational material. No API key required.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Safe,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@kimtaeyoon83/mcp-server-youtube-transcript'],
                    'env' => [],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'get_transcript',
                        'description' => 'Get the transcript of a YouTube video',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'url' => ['type' => 'string', 'description' => 'YouTube video URL or ID'],
                                'lang' => ['type' => 'string', 'description' => 'Language code (default: en)'],
                            ],
                            'required' => ['url'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 30],
            ],

            [
                'name' => 'Atlassian (Jira + Confluence)',
                'slug' => 'atlassian',
                'description' => 'Interact with Jira and Confluence — search and create issues, read and update wiki pages, manage projects. Requires Atlassian API token and site URL.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@anthropic-ai/mcp-server-atlassian'],
                    'env' => [
                        'ATLASSIAN_SITE_URL' => '',
                        'ATLASSIAN_USER_EMAIL' => '',
                        'ATLASSIAN_API_TOKEN' => '',
                    ],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'jira_search_issues',
                        'description' => 'Search Jira issues using JQL',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'jql' => ['type' => 'string', 'description' => 'JQL query string'],
                                'maxResults' => ['type' => 'integer', 'description' => 'Max results (default: 50)'],
                            ],
                            'required' => ['jql'],
                        ],
                    ],
                    [
                        'name' => 'jira_create_issue',
                        'description' => 'Create a new Jira issue',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'projectKey' => ['type' => 'string', 'description' => 'Project key (e.g., PROJ)'],
                                'summary' => ['type' => 'string', 'description' => 'Issue summary'],
                                'issueType' => ['type' => 'string', 'description' => 'Issue type (Bug, Task, Story)'],
                                'description' => ['type' => 'string', 'description' => 'Issue description'],
                            ],
                            'required' => ['projectKey', 'summary', 'issueType'],
                        ],
                    ],
                    [
                        'name' => 'confluence_search',
                        'description' => 'Search Confluence pages',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Search query (CQL)'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'confluence_get_page',
                        'description' => 'Get a Confluence page by ID',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'pageId' => ['type' => 'string', 'description' => 'Page ID'],
                            ],
                            'required' => ['pageId'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 20],
            ],

            [
                'name' => 'Figma',
                'slug' => 'figma',
                'description' => 'Read Figma design files — get file structure, component details, styles, and design tokens. Useful for design-to-code workflows. Requires a Figma access token.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Read,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@anthropic-ai/mcp-server-figma'],
                    'env' => ['FIGMA_ACCESS_TOKEN' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'figma_get_file',
                        'description' => 'Get the structure and details of a Figma file',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'fileKey' => ['type' => 'string', 'description' => 'Figma file key (from URL)'],
                                'nodeId' => ['type' => 'string', 'description' => 'Specific node ID to fetch (optional)'],
                                'depth' => ['type' => 'integer', 'description' => 'Tree depth to fetch (optional)'],
                            ],
                            'required' => ['fileKey'],
                        ],
                    ],
                    [
                        'name' => 'figma_get_styles',
                        'description' => 'Get all styles (colors, typography, effects) from a Figma file',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'fileKey' => ['type' => 'string', 'description' => 'Figma file key'],
                            ],
                            'required' => ['fileKey'],
                        ],
                    ],
                    [
                        'name' => 'figma_get_components',
                        'description' => 'List all components in a Figma file',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'fileKey' => ['type' => 'string', 'description' => 'Figma file key'],
                            ],
                            'required' => ['fileKey'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 30],
            ],

            [
                'name' => 'Pipedream Connect',
                'slug' => 'pipedream',
                'description' => 'Connect to 2400+ APIs and services via Pipedream — trigger workflows, make API calls, process webhooks. Acts as a universal integration bridge. Requires a Pipedream API key.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@pipedream/mcp'],
                    'env' => ['PIPEDREAM_API_KEY' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'pipedream_list_apps',
                        'description' => 'List available Pipedream app integrations',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Search query for apps'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'pipedream_run_action',
                        'description' => 'Run a Pipedream action (API call to any connected service)',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'app' => ['type' => 'string', 'description' => 'App slug (e.g., google_sheets, stripe)'],
                                'action' => ['type' => 'string', 'description' => 'Action to run'],
                                'params' => ['type' => 'object', 'description' => 'Action parameters'],
                            ],
                            'required' => ['app', 'action'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 30],
            ],

            [
                'name' => 'AWS',
                'slug' => 'aws',
                'description' => 'Interact with AWS services — manage S3 buckets, query DynamoDB, invoke Lambda functions, read CloudWatch logs, and more. Requires AWS credentials (access key + secret).',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Destructive,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@anthropic-ai/mcp-server-aws'],
                    'env' => [
                        'AWS_ACCESS_KEY_ID' => '',
                        'AWS_SECRET_ACCESS_KEY' => '',
                        'AWS_REGION' => 'us-east-1',
                    ],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'aws_s3_list',
                        'description' => 'List S3 buckets or objects in a bucket',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'bucket' => ['type' => 'string', 'description' => 'Bucket name (optional, lists all buckets if empty)'],
                                'prefix' => ['type' => 'string', 'description' => 'Object key prefix filter'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'aws_s3_get',
                        'description' => 'Get an object from S3',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'bucket' => ['type' => 'string', 'description' => 'Bucket name'],
                                'key' => ['type' => 'string', 'description' => 'Object key'],
                            ],
                            'required' => ['bucket', 'key'],
                        ],
                    ],
                    [
                        'name' => 'aws_lambda_invoke',
                        'description' => 'Invoke an AWS Lambda function',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'functionName' => ['type' => 'string', 'description' => 'Lambda function name or ARN'],
                                'payload' => ['type' => 'object', 'description' => 'JSON payload to send'],
                            ],
                            'required' => ['functionName'],
                        ],
                    ],
                    [
                        'name' => 'aws_cloudwatch_logs',
                        'description' => 'Query CloudWatch log groups',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'logGroupName' => ['type' => 'string', 'description' => 'Log group name'],
                                'filterPattern' => ['type' => 'string', 'description' => 'Filter pattern'],
                                'limit' => ['type' => 'integer', 'description' => 'Max log events to return'],
                            ],
                            'required' => ['logGroupName'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 30],
            ],

            [
                'name' => 'Pollinations Image',
                'slug' => 'pollinations-image',
                'description' => 'Generate images from text prompts using Pollinations AI. Supports various styles, sizes, and models. Free, no API key required.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Safe,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@pollinations/model-context-protocol'],
                    'env' => [],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'generate_image',
                        'description' => 'Generate an image from a text prompt',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'prompt' => ['type' => 'string', 'description' => 'Text prompt describing the image to generate'],
                                'width' => ['type' => 'integer', 'description' => 'Image width in pixels (default: 1024)'],
                                'height' => ['type' => 'integer', 'description' => 'Image height in pixels (default: 1024)'],
                                'model' => ['type' => 'string', 'description' => 'Model to use (default: flux)'],
                                'seed' => ['type' => 'integer', 'description' => 'Random seed for reproducibility'],
                            ],
                            'required' => ['prompt'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 60],
            ],

            // ─── Databases ───────────────────────────────────────────

            [
                'name' => 'SQLite',
                'slug' => 'sqlite',
                'description' => 'Run SQL queries and manage a local SQLite database. Create tables, insert data, query records, and explore schema. Ideal for lightweight data storage in agent workflows. No external service required.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@modelcontextprotocol/server-sqlite', '/tmp/agent-workspace/agent.db'],
                    'env' => [],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'read_query',
                        'description' => 'Execute a SELECT query and return results',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'SELECT SQL query'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'write_query',
                        'description' => 'Execute an INSERT, UPDATE, or DELETE query',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'SQL write query'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'create_table',
                        'description' => 'Create a new table in the database',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'CREATE TABLE SQL statement'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'list_tables',
                        'description' => 'List all tables in the database',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                    [
                        'name' => 'describe_table',
                        'description' => 'Get the schema of a specific table',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'table_name' => ['type' => 'string', 'description' => 'Table name to describe'],
                            ],
                            'required' => ['table_name'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            [
                'name' => 'MySQL',
                'slug' => 'mysql',
                'description' => 'Execute SQL queries against a MySQL database. Supports read and write operations, schema inspection, and multi-database access. Requires a MySQL connection URL.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@benborla29/mcp-server-mysql'],
                    'env' => ['MYSQL_HOST' => '', 'MYSQL_PORT' => '3306', 'MYSQL_USER' => '', 'MYSQL_PASS' => '', 'MYSQL_DB' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'mysql_query',
                        'description' => 'Execute a SQL query against the MySQL database',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'SQL query to execute'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'mysql_list_tables',
                        'description' => 'List all tables in the current database',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                    [
                        'name' => 'mysql_describe_table',
                        'description' => 'Describe the schema of a table',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'table' => ['type' => 'string', 'description' => 'Table name'],
                            ],
                            'required' => ['table'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 30],
            ],

            [
                'name' => 'Supabase',
                'slug' => 'supabase',
                'description' => 'Interact with your Supabase project — query tables, manage auth users, invoke Edge Functions, and access Storage. Requires a Supabase project URL and service role key.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@supabase/mcp-server-supabase@latest'],
                    'env' => ['SUPABASE_URL' => '', 'SUPABASE_SERVICE_ROLE_KEY' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'supabase_query',
                        'description' => 'Execute a SQL query on the Supabase PostgreSQL database',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'SQL query'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'supabase_list_tables',
                        'description' => 'List all tables in the Supabase database',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                    [
                        'name' => 'supabase_invoke_function',
                        'description' => 'Invoke a Supabase Edge Function',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'function_name' => ['type' => 'string', 'description' => 'Edge Function name'],
                                'body' => ['type' => 'object', 'description' => 'Request body'],
                            ],
                            'required' => ['function_name'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 30],
            ],

            // ─── Cloud & Infrastructure ───────────────────────────────

            [
                'name' => 'Cloudflare',
                'slug' => 'cloudflare',
                'description' => 'Manage Cloudflare resources — DNS records, Workers scripts, KV storage, R2 buckets, D1 databases, and analytics. Requires a Cloudflare API token.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@cloudflare/mcp-server-cloudflare'],
                    'env' => ['CLOUDFLARE_API_TOKEN' => '', 'CLOUDFLARE_ACCOUNT_ID' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'cloudflare_dns_list',
                        'description' => 'List DNS records for a zone',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'zone_id' => ['type' => 'string', 'description' => 'Cloudflare zone ID'],
                            ],
                            'required' => ['zone_id'],
                        ],
                    ],
                    [
                        'name' => 'cloudflare_kv_get',
                        'description' => 'Get a value from a KV namespace',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'namespace_id' => ['type' => 'string', 'description' => 'KV namespace ID'],
                                'key' => ['type' => 'string', 'description' => 'Key to retrieve'],
                            ],
                            'required' => ['namespace_id', 'key'],
                        ],
                    ],
                    [
                        'name' => 'cloudflare_kv_put',
                        'description' => 'Put a value in a KV namespace',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'namespace_id' => ['type' => 'string', 'description' => 'KV namespace ID'],
                                'key' => ['type' => 'string', 'description' => 'Key to set'],
                                'value' => ['type' => 'string', 'description' => 'Value to store'],
                            ],
                            'required' => ['namespace_id', 'key', 'value'],
                        ],
                    ],
                    [
                        'name' => 'cloudflare_worker_deploy',
                        'description' => 'Deploy a Worker script',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'description' => 'Worker name'],
                                'script' => ['type' => 'string', 'description' => 'Worker script source'],
                            ],
                            'required' => ['name', 'script'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 20],
            ],

            [
                'name' => 'Vercel',
                'slug' => 'vercel',
                'description' => 'Manage Vercel deployments — trigger deploys, list projects, check deployment status, and manage environment variables. Requires a Vercel API token.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@vercel/mcp-adapter@latest'],
                    'env' => ['VERCEL_TOKEN' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'vercel_list_projects',
                        'description' => 'List all Vercel projects',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                    [
                        'name' => 'vercel_list_deployments',
                        'description' => 'List recent deployments for a project',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'projectId' => ['type' => 'string', 'description' => 'Project ID or name'],
                                'limit' => ['type' => 'integer', 'description' => 'Number of deployments to return'],
                            ],
                            'required' => ['projectId'],
                        ],
                    ],
                    [
                        'name' => 'vercel_get_deployment',
                        'description' => 'Get details of a specific deployment',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'deploymentId' => ['type' => 'string', 'description' => 'Deployment ID'],
                            ],
                            'required' => ['deploymentId'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 20],
            ],

            // ─── Payments & CRM ───────────────────────────────────────

            [
                'name' => 'Stripe',
                'slug' => 'stripe',
                'description' => 'Manage Stripe payments — list and create customers, retrieve charges, manage subscriptions, issue refunds, and query payment intents. Requires a Stripe secret key.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@stripe/mcp@latest', '--tools=all'],
                    'env' => ['STRIPE_SECRET_KEY' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'stripe_list_customers',
                        'description' => 'List Stripe customers',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'limit' => ['type' => 'integer', 'description' => 'Max customers to return (default: 10)'],
                                'email' => ['type' => 'string', 'description' => 'Filter by email address'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'stripe_list_charges',
                        'description' => 'List recent Stripe charges',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'limit' => ['type' => 'integer', 'description' => 'Max charges to return (default: 10)'],
                                'customer' => ['type' => 'string', 'description' => 'Filter by customer ID'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'stripe_create_refund',
                        'description' => 'Issue a refund for a charge',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'charge' => ['type' => 'string', 'description' => 'Charge ID to refund'],
                                'amount' => ['type' => 'integer', 'description' => 'Amount to refund in cents (optional, full refund if omitted)'],
                            ],
                            'required' => ['charge'],
                        ],
                    ],
                    [
                        'name' => 'stripe_list_subscriptions',
                        'description' => 'List active subscriptions',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'customer' => ['type' => 'string', 'description' => 'Filter by customer ID'],
                                'status' => ['type' => 'string', 'description' => 'Filter by status: active, past_due, canceled'],
                            ],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 20],
            ],

            [
                'name' => 'HubSpot',
                'slug' => 'hubspot',
                'description' => 'Manage HubSpot CRM — search and create contacts, companies, and deals; log activities; retrieve pipelines and properties. Requires a HubSpot private app access token.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@hubspot/mcp-server@latest'],
                    'env' => ['HUBSPOT_ACCESS_TOKEN' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'hubspot_search_contacts',
                        'description' => 'Search HubSpot contacts',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Search query (email, name, company)'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'hubspot_create_contact',
                        'description' => 'Create a new HubSpot contact',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'email' => ['type' => 'string', 'description' => 'Contact email'],
                                'firstname' => ['type' => 'string', 'description' => 'First name'],
                                'lastname' => ['type' => 'string', 'description' => 'Last name'],
                                'company' => ['type' => 'string', 'description' => 'Company name'],
                            ],
                            'required' => ['email'],
                        ],
                    ],
                    [
                        'name' => 'hubspot_list_deals',
                        'description' => 'List deals in a pipeline',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'pipeline_id' => ['type' => 'string', 'description' => 'Pipeline ID (optional)'],
                                'stage_id' => ['type' => 'string', 'description' => 'Stage ID filter (optional)'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'hubspot_create_deal',
                        'description' => 'Create a new deal in HubSpot',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'dealname' => ['type' => 'string', 'description' => 'Deal name'],
                                'amount' => ['type' => 'number', 'description' => 'Deal amount'],
                                'pipeline' => ['type' => 'string', 'description' => 'Pipeline ID'],
                                'dealstage' => ['type' => 'string', 'description' => 'Stage ID'],
                            ],
                            'required' => ['dealname'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 20],
            ],

            // ─── Communication ────────────────────────────────────────

            [
                'name' => 'Resend',
                'slug' => 'resend',
                'description' => 'Send transactional and marketing emails via Resend. Supports HTML and plain text, attachments, reply-to headers, and bulk sends. Requires a Resend API key.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', 'resend-mcp'],
                    'env' => ['RESEND_API_KEY' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'resend_send_email',
                        'description' => 'Send an email via Resend',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'from' => ['type' => 'string', 'description' => 'Sender email (must be a verified domain)'],
                                'to' => ['type' => 'array', 'description' => 'Recipient email addresses'],
                                'subject' => ['type' => 'string', 'description' => 'Email subject'],
                                'html' => ['type' => 'string', 'description' => 'HTML email body'],
                                'text' => ['type' => 'string', 'description' => 'Plain text fallback'],
                            ],
                            'required' => ['from', 'to', 'subject'],
                        ],
                    ],
                    [
                        'name' => 'resend_list_emails',
                        'description' => 'List recently sent emails',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'limit' => ['type' => 'integer', 'description' => 'Number of emails to return (default: 10)'],
                            ],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            [
                'name' => 'Twilio',
                'slug' => 'twilio',
                'description' => 'Send SMS and WhatsApp messages via Twilio. List phone numbers, send messages, and check delivery status. Requires Twilio Account SID and Auth Token.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@twilio/mcp@latest'],
                    'env' => ['TWILIO_ACCOUNT_SID' => '', 'TWILIO_AUTH_TOKEN' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'twilio_send_sms',
                        'description' => 'Send an SMS message',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'to' => ['type' => 'string', 'description' => 'Recipient phone number (E.164 format, e.g. +12025551234)'],
                                'from' => ['type' => 'string', 'description' => 'Twilio phone number to send from'],
                                'body' => ['type' => 'string', 'description' => 'Message text (max 1600 chars)'],
                            ],
                            'required' => ['to', 'from', 'body'],
                        ],
                    ],
                    [
                        'name' => 'twilio_list_messages',
                        'description' => 'List sent/received messages',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'to' => ['type' => 'string', 'description' => 'Filter by recipient number'],
                                'from' => ['type' => 'string', 'description' => 'Filter by sender number'],
                                'limit' => ['type' => 'integer', 'description' => 'Max messages to return'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'twilio_list_phone_numbers',
                        'description' => 'List Twilio phone numbers in the account',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            [
                'name' => 'Discord',
                'slug' => 'discord',
                'description' => 'Send messages, read channels, and manage Discord servers. Post announcements, read message history, and search content. Requires a Discord Bot token.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@binalfew/mcp-server-discord@latest'],
                    'env' => ['DISCORD_TOKEN' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'discord_list_guilds',
                        'description' => 'List all guilds (servers) the bot is in',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                    [
                        'name' => 'discord_list_channels',
                        'description' => 'List channels in a guild',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'guild_id' => ['type' => 'string', 'description' => 'Guild (server) ID'],
                            ],
                            'required' => ['guild_id'],
                        ],
                    ],
                    [
                        'name' => 'discord_send_message',
                        'description' => 'Send a message to a Discord channel',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'channel_id' => ['type' => 'string', 'description' => 'Channel ID to send to'],
                                'content' => ['type' => 'string', 'description' => 'Message content (up to 2000 chars)'],
                            ],
                            'required' => ['channel_id', 'content'],
                        ],
                    ],
                    [
                        'name' => 'discord_read_messages',
                        'description' => 'Read recent messages from a channel',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'channel_id' => ['type' => 'string', 'description' => 'Channel ID'],
                                'limit' => ['type' => 'integer', 'description' => 'Number of messages (default: 50, max: 100)'],
                            ],
                            'required' => ['channel_id'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            // ─── Productivity & Data ──────────────────────────────────

            [
                'name' => 'Airtable',
                'slug' => 'airtable',
                'description' => 'Query and update Airtable bases — list tables, read and filter records, create/update rows, and search content. Works like a spreadsheet database. Requires an Airtable API key.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@anthropic-ai/mcp-server-airtable'],
                    'env' => ['AIRTABLE_API_KEY' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'airtable_list_bases',
                        'description' => 'List all accessible Airtable bases',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                    [
                        'name' => 'airtable_list_tables',
                        'description' => 'List tables in a base',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'base_id' => ['type' => 'string', 'description' => 'Airtable base ID'],
                            ],
                            'required' => ['base_id'],
                        ],
                    ],
                    [
                        'name' => 'airtable_list_records',
                        'description' => 'List records from a table with optional filtering',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'base_id' => ['type' => 'string', 'description' => 'Base ID'],
                                'table_name' => ['type' => 'string', 'description' => 'Table name'],
                                'filter' => ['type' => 'string', 'description' => 'Airtable formula filter'],
                                'max_records' => ['type' => 'integer', 'description' => 'Max records to return (default: 100)'],
                            ],
                            'required' => ['base_id', 'table_name'],
                        ],
                    ],
                    [
                        'name' => 'airtable_create_record',
                        'description' => 'Create a new record in a table',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'base_id' => ['type' => 'string', 'description' => 'Base ID'],
                                'table_name' => ['type' => 'string', 'description' => 'Table name'],
                                'fields' => ['type' => 'object', 'description' => 'Record fields as key-value pairs'],
                            ],
                            'required' => ['base_id', 'table_name', 'fields'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            [
                'name' => 'Google Drive',
                'slug' => 'google-drive',
                'description' => 'Access and manage Google Drive files — list files, search by name or content, read documents, and upload files. Requires Google OAuth credentials.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@googleapis/mcp-server-drive'],
                    'env' => [
                        'GOOGLE_CLIENT_ID' => '',
                        'GOOGLE_CLIENT_SECRET' => '',
                        'GOOGLE_REFRESH_TOKEN' => '',
                    ],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'drive_list_files',
                        'description' => 'List files in Google Drive',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Search query (Google Drive query syntax)'],
                                'page_size' => ['type' => 'integer', 'description' => 'Number of files to return (default: 20)'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'drive_read_file',
                        'description' => 'Read the content of a Google Drive file',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'file_id' => ['type' => 'string', 'description' => 'Google Drive file ID'],
                            ],
                            'required' => ['file_id'],
                        ],
                    ],
                    [
                        'name' => 'drive_create_file',
                        'description' => 'Create or upload a file to Google Drive',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'description' => 'File name'],
                                'content' => ['type' => 'string', 'description' => 'File content'],
                                'mime_type' => ['type' => 'string', 'description' => 'MIME type (default: text/plain)'],
                                'folder_id' => ['type' => 'string', 'description' => 'Parent folder ID (optional)'],
                            ],
                            'required' => ['name', 'content'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 20],
            ],

            [
                'name' => 'Tavily',
                'slug' => 'tavily',
                'description' => 'AI-optimised web search designed for LLM agents. Returns structured, clean search results with source URLs and answer summaries. More accurate than general search for research tasks. Requires a Tavily API key.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Safe,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', 'tavily-mcp@latest'],
                    'env' => ['TAVILY_API_KEY' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'tavily_search',
                        'description' => 'Search the web with Tavily AI search',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Search query'],
                                'search_depth' => ['type' => 'string', 'description' => 'Search depth: basic or advanced (default: basic)'],
                                'max_results' => ['type' => 'integer', 'description' => 'Max results to return (default: 5)'],
                                'include_answer' => ['type' => 'boolean', 'description' => 'Include LLM-generated answer summary'],
                                'include_domains' => ['type' => 'array', 'description' => 'Domains to include in search'],
                                'exclude_domains' => ['type' => 'array', 'description' => 'Domains to exclude from search'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'tavily_extract',
                        'description' => 'Extract content from specific URLs',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'urls' => ['type' => 'array', 'description' => 'URLs to extract content from'],
                            ],
                            'required' => ['urls'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 20],
            ],

            // ─── Productivity & Project Management ────────────────────

            [
                'name' => 'Google Sheets',
                'slug' => 'google-sheets',
                'description' => 'Read and write Google Sheets spreadsheets — get cell values, update ranges, append rows, and create new sheets. Ideal for agents that store or retrieve structured data. Requires Google OAuth credentials.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@googleapis/mcp-server-sheets'],
                    'env' => [
                        'GOOGLE_CLIENT_ID' => '',
                        'GOOGLE_CLIENT_SECRET' => '',
                        'GOOGLE_REFRESH_TOKEN' => '',
                    ],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'sheets_get_values',
                        'description' => 'Read values from a range in a Google Sheet',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'spreadsheet_id' => ['type' => 'string', 'description' => 'Spreadsheet ID (from URL)'],
                                'range' => ['type' => 'string', 'description' => 'A1 notation range (e.g. Sheet1!A1:D10)'],
                            ],
                            'required' => ['spreadsheet_id', 'range'],
                        ],
                    ],
                    [
                        'name' => 'sheets_update_values',
                        'description' => 'Write values to a range in a Google Sheet',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'spreadsheet_id' => ['type' => 'string', 'description' => 'Spreadsheet ID'],
                                'range' => ['type' => 'string', 'description' => 'A1 notation range'],
                                'values' => ['type' => 'array', 'description' => '2D array of values to write'],
                            ],
                            'required' => ['spreadsheet_id', 'range', 'values'],
                        ],
                    ],
                    [
                        'name' => 'sheets_append_values',
                        'description' => 'Append rows to a Google Sheet',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'spreadsheet_id' => ['type' => 'string', 'description' => 'Spreadsheet ID'],
                                'range' => ['type' => 'string', 'description' => 'Target range (e.g. Sheet1!A:Z)'],
                                'values' => ['type' => 'array', 'description' => '2D array of rows to append'],
                            ],
                            'required' => ['spreadsheet_id', 'range', 'values'],
                        ],
                    ],
                    [
                        'name' => 'sheets_list_spreadsheets',
                        'description' => 'List spreadsheets in Google Drive',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Optional name search query'],
                            ],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 20],
            ],

            [
                'name' => 'Google Calendar',
                'slug' => 'google-calendar',
                'description' => 'Manage Google Calendar — list events, create appointments, check availability, and update or delete events. Useful for scheduling agents and assistants. Requires Google OAuth credentials.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@googleapis/mcp-server-calendar'],
                    'env' => [
                        'GOOGLE_CLIENT_ID' => '',
                        'GOOGLE_CLIENT_SECRET' => '',
                        'GOOGLE_REFRESH_TOKEN' => '',
                    ],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'calendar_list_events',
                        'description' => 'List upcoming calendar events',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'calendar_id' => ['type' => 'string', 'description' => 'Calendar ID (use "primary" for main calendar)'],
                                'time_min' => ['type' => 'string', 'description' => 'Start of time range (ISO 8601, e.g. 2025-01-01T00:00:00Z)'],
                                'time_max' => ['type' => 'string', 'description' => 'End of time range (ISO 8601)'],
                                'max_results' => ['type' => 'integer', 'description' => 'Max events to return (default: 10)'],
                            ],
                            'required' => ['calendar_id'],
                        ],
                    ],
                    [
                        'name' => 'calendar_create_event',
                        'description' => 'Create a new calendar event',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'calendar_id' => ['type' => 'string', 'description' => 'Calendar ID'],
                                'summary' => ['type' => 'string', 'description' => 'Event title'],
                                'description' => ['type' => 'string', 'description' => 'Event description'],
                                'start' => ['type' => 'string', 'description' => 'Start time (ISO 8601)'],
                                'end' => ['type' => 'string', 'description' => 'End time (ISO 8601)'],
                                'attendees' => ['type' => 'array', 'description' => 'Array of attendee email addresses'],
                            ],
                            'required' => ['calendar_id', 'summary', 'start', 'end'],
                        ],
                    ],
                    [
                        'name' => 'calendar_delete_event',
                        'description' => 'Delete a calendar event',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'calendar_id' => ['type' => 'string', 'description' => 'Calendar ID'],
                                'event_id' => ['type' => 'string', 'description' => 'Event ID to delete'],
                            ],
                            'required' => ['calendar_id', 'event_id'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            [
                'name' => 'Monday.com',
                'slug' => 'monday',
                'description' => 'Manage Monday.com boards — read and update items, add updates, create new items, and query workspaces. Useful for project tracking agents. Requires a Monday.com API token.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@mondaydotcom/mcp-server'],
                    'env' => ['MONDAY_API_TOKEN' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'monday_list_boards',
                        'description' => 'List Monday.com boards',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'limit' => ['type' => 'integer', 'description' => 'Max boards to return (default: 10)'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'monday_get_items',
                        'description' => 'Get items from a Monday.com board',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'board_id' => ['type' => 'string', 'description' => 'Board ID'],
                                'limit' => ['type' => 'integer', 'description' => 'Max items to return'],
                            ],
                            'required' => ['board_id'],
                        ],
                    ],
                    [
                        'name' => 'monday_create_item',
                        'description' => 'Create a new item on a Monday.com board',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'board_id' => ['type' => 'string', 'description' => 'Board ID'],
                                'item_name' => ['type' => 'string', 'description' => 'Item name'],
                                'column_values' => ['type' => 'string', 'description' => 'Column values as JSON string'],
                            ],
                            'required' => ['board_id', 'item_name'],
                        ],
                    ],
                    [
                        'name' => 'monday_add_update',
                        'description' => 'Post an update/comment on a Monday.com item',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'item_id' => ['type' => 'string', 'description' => 'Item ID'],
                                'body' => ['type' => 'string', 'description' => 'Update text'],
                            ],
                            'required' => ['item_id', 'body'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            // ─── E-commerce ───────────────────────────────────────────

            [
                'name' => 'Shopify',
                'slug' => 'shopify',
                'description' => 'Manage a Shopify store — browse products, manage orders, update inventory, handle customers, and apply discounts. Ideal for e-commerce automation agents. Requires a Shopify Admin API token.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@shopify/dev-mcp@latest'],
                    'env' => ['SHOPIFY_STORE_URL' => '', 'SHOPIFY_ADMIN_API_TOKEN' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'shopify_list_products',
                        'description' => 'List products in the Shopify store',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'limit' => ['type' => 'integer', 'description' => 'Max products to return (default: 50)'],
                                'status' => ['type' => 'string', 'description' => 'Filter by status: active, draft, archived'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'shopify_get_order',
                        'description' => 'Get a specific Shopify order by ID',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'order_id' => ['type' => 'string', 'description' => 'Order ID'],
                            ],
                            'required' => ['order_id'],
                        ],
                    ],
                    [
                        'name' => 'shopify_list_orders',
                        'description' => 'List recent Shopify orders',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'status' => ['type' => 'string', 'description' => 'Order status: open, closed, cancelled, any (default: open)'],
                                'limit' => ['type' => 'integer', 'description' => 'Max orders to return (default: 50)'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'shopify_update_product',
                        'description' => 'Update a product in the Shopify store',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'product_id' => ['type' => 'string', 'description' => 'Product ID'],
                                'title' => ['type' => 'string', 'description' => 'New product title'],
                                'body_html' => ['type' => 'string', 'description' => 'Product description (HTML)'],
                                'status' => ['type' => 'string', 'description' => 'Product status: active, draft, archived'],
                            ],
                            'required' => ['product_id'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 20],
            ],

            // ─── Monitoring & Support ─────────────────────────────────

            [
                'name' => 'Datadog',
                'slug' => 'datadog',
                'description' => 'Monitor infrastructure and applications via Datadog — query metrics, search logs, list monitors and alerts, inspect dashboards, and get incident details. Requires a Datadog API key and App key.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Read,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@datadog/mcp-server'],
                    'env' => ['DD_API_KEY' => '', 'DD_APP_KEY' => '', 'DD_SITE' => 'datadoghq.com'],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'datadog_query_metrics',
                        'description' => 'Query Datadog metrics for a time range',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Datadog metric query (e.g. avg:system.cpu.user{*})'],
                                'from' => ['type' => 'integer', 'description' => 'Start time as Unix timestamp'],
                                'to' => ['type' => 'integer', 'description' => 'End time as Unix timestamp'],
                            ],
                            'required' => ['query', 'from', 'to'],
                        ],
                    ],
                    [
                        'name' => 'datadog_list_monitors',
                        'description' => 'List Datadog monitors and their current status',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'description' => 'Filter monitors by name'],
                                'tags' => ['type' => 'string', 'description' => 'Filter by tags (comma-separated)'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'datadog_search_logs',
                        'description' => 'Search Datadog logs',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Log search query'],
                                'from' => ['type' => 'string', 'description' => 'Start time (ISO 8601 or relative like "15m")'],
                                'to' => ['type' => 'string', 'description' => 'End time'],
                                'limit' => ['type' => 'integer', 'description' => 'Max log lines to return (default: 50)'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 20],
            ],

            [
                'name' => 'Zendesk',
                'slug' => 'zendesk',
                'description' => 'Manage Zendesk support tickets — list, search, create, and update tickets; add comments; manage users and organisations. Ideal for customer support automation. Requires Zendesk subdomain, email, and API token.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@zendesk/mcp-server'],
                    'env' => ['ZENDESK_SUBDOMAIN' => '', 'ZENDESK_EMAIL' => '', 'ZENDESK_API_TOKEN' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'zendesk_list_tickets',
                        'description' => 'List recent Zendesk tickets',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'status' => ['type' => 'string', 'description' => 'Filter by status: new, open, pending, solved, closed'],
                                'assignee_id' => ['type' => 'string', 'description' => 'Filter by assignee'],
                                'per_page' => ['type' => 'integer', 'description' => 'Results per page (default: 25)'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'zendesk_get_ticket',
                        'description' => 'Get a Zendesk ticket by ID',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'ticket_id' => ['type' => 'integer', 'description' => 'Ticket ID'],
                            ],
                            'required' => ['ticket_id'],
                        ],
                    ],
                    [
                        'name' => 'zendesk_create_ticket',
                        'description' => 'Create a new Zendesk support ticket',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'subject' => ['type' => 'string', 'description' => 'Ticket subject'],
                                'body' => ['type' => 'string', 'description' => 'Ticket description'],
                                'requester_email' => ['type' => 'string', 'description' => 'Requester email address'],
                                'priority' => ['type' => 'string', 'description' => 'Priority: low, normal, high, urgent'],
                            ],
                            'required' => ['subject', 'body'],
                        ],
                    ],
                    [
                        'name' => 'zendesk_add_comment',
                        'description' => 'Add a comment to a Zendesk ticket',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'ticket_id' => ['type' => 'integer', 'description' => 'Ticket ID'],
                                'body' => ['type' => 'string', 'description' => 'Comment text'],
                                'public' => ['type' => 'boolean', 'description' => 'Whether the comment is public (default: true)'],
                            ],
                            'required' => ['ticket_id', 'body'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            // ─── Email Marketing ──────────────────────────────────────

            [
                'name' => 'Mailchimp',
                'slug' => 'mailchimp',
                'description' => 'Manage Mailchimp email marketing — list audiences and campaigns, add/update contacts, send campaigns, and check campaign stats. Requires a Mailchimp API key.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@anthropic-ai/mcp-server-mailchimp'],
                    'env' => ['MAILCHIMP_API_KEY' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'mailchimp_list_audiences',
                        'description' => 'List Mailchimp audiences/lists',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                    [
                        'name' => 'mailchimp_add_member',
                        'description' => 'Add or update a contact in a Mailchimp audience',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'list_id' => ['type' => 'string', 'description' => 'Audience/list ID'],
                                'email_address' => ['type' => 'string', 'description' => 'Contact email'],
                                'first_name' => ['type' => 'string', 'description' => 'First name'],
                                'last_name' => ['type' => 'string', 'description' => 'Last name'],
                                'status' => ['type' => 'string', 'description' => 'Status: subscribed, unsubscribed, pending (default: subscribed)'],
                            ],
                            'required' => ['list_id', 'email_address'],
                        ],
                    ],
                    [
                        'name' => 'mailchimp_list_campaigns',
                        'description' => 'List recent email campaigns and their stats',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'count' => ['type' => 'integer', 'description' => 'Max campaigns to return (default: 10)'],
                                'status' => ['type' => 'string', 'description' => 'Filter by status: save, paused, schedule, sending, sent'],
                            ],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            // ─── Social & Community ───────────────────────────────────

            [
                'name' => 'Reddit',
                'slug' => 'reddit',
                'description' => 'Browse Reddit — search posts, read subreddits, get comments, and find trending content. Useful for research, market intelligence, and monitoring community discussions. No API key required for public data.',
                'type' => ToolType::McpStdio,
                'risk_level' => ToolRiskLevel::Safe,
                'transport_config' => [
                    'command' => 'npx',
                    'args' => ['-y', '@anthropic-ai/mcp-server-reddit'],
                    'env' => [],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'reddit_search',
                        'description' => 'Search Reddit posts across all subreddits',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Search query'],
                                'subreddit' => ['type' => 'string', 'description' => 'Limit search to a specific subreddit (optional)'],
                                'sort' => ['type' => 'string', 'description' => 'Sort by: relevance, new, top, hot (default: relevance)'],
                                'limit' => ['type' => 'integer', 'description' => 'Number of results (default: 10)'],
                                'time' => ['type' => 'string', 'description' => 'Time filter: hour, day, week, month, year, all'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name' => 'reddit_get_subreddit',
                        'description' => 'Get top/hot posts from a subreddit',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'subreddit' => ['type' => 'string', 'description' => 'Subreddit name (without r/)'],
                                'sort' => ['type' => 'string', 'description' => 'Sort by: hot, new, top, rising (default: hot)'],
                                'limit' => ['type' => 'integer', 'description' => 'Number of posts (default: 10)'],
                            ],
                            'required' => ['subreddit'],
                        ],
                    ],
                    [
                        'name' => 'reddit_get_post_comments',
                        'description' => 'Get comments on a Reddit post',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'post_url' => ['type' => 'string', 'description' => 'Full Reddit post URL'],
                                'limit' => ['type' => 'integer', 'description' => 'Number of top-level comments to return (default: 20)'],
                            ],
                            'required' => ['post_url'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 15],
            ],

            // ─── Marketplace Connectors (HTTP) ────────────────────────
            // These expose 1000s of pre-built actions via a single MCP endpoint.
            // Enable one to give your agents access to virtually any SaaS tool.

            [
                'name' => 'Zapier',
                'slug' => 'zapier',
                'description' => 'Connect agents to 7,000+ apps via Zapier. Each Zapier action you configure becomes a callable tool — send Slack messages, update CRM records, post to social media, and more, without building custom integrations. Copy your personal MCP server URL from zapier.com/mcp.',
                'type' => ToolType::McpHttp,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'url' => '',
                    'headers' => [],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'zapier_run_action',
                        'description' => 'Run a configured Zapier action',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'instructions' => ['type' => 'string', 'description' => 'Natural language description of what to do'],
                            ],
                            'required' => ['instructions'],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 60],
            ],

            [
                'name' => 'Make',
                'slug' => 'make',
                'description' => 'Connect agents to 2,000+ apps via Make (formerly Integromat). Trigger Make scenarios, pass data between apps, and automate complex multi-step workflows. Copy your MCP endpoint URL from make.com/mcp.',
                'type' => ToolType::McpHttp,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'url' => '',
                    'headers' => ['Authorization' => 'Token '],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'make_run_scenario',
                        'description' => 'Trigger a Make scenario and pass data to it',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'scenario_id' => ['type' => 'string', 'description' => 'Make scenario ID to trigger'],
                                'data' => ['type' => 'object', 'description' => 'Input data to pass to the scenario'],
                            ],
                            'required' => ['scenario_id'],
                        ],
                    ],
                    [
                        'name' => 'make_list_scenarios',
                        'description' => 'List available Make scenarios',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'team_id' => ['type' => 'string', 'description' => 'Make team ID (optional)'],
                            ],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 60],
            ],

            [
                'name' => 'n8n',
                'slug' => 'n8n',
                'description' => 'Connect agents to your self-hosted n8n workflows. Trigger automations, pass data to n8n workflows, and retrieve results. n8n has 400+ integrations. Requires a running n8n instance with the MCP extension enabled.',
                'type' => ToolType::McpHttp,
                'risk_level' => ToolRiskLevel::Write,
                'transport_config' => [
                    'url' => '',
                    'headers' => ['X-N8N-API-KEY' => ''],
                ],
                'tool_definitions' => [
                    [
                        'name' => 'n8n_execute_workflow',
                        'description' => 'Execute an n8n workflow by ID',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'workflow_id' => ['type' => 'string', 'description' => 'n8n workflow ID'],
                                'data' => ['type' => 'object', 'description' => 'Input data to send to the workflow'],
                            ],
                            'required' => ['workflow_id'],
                        ],
                    ],
                    [
                        'name' => 'n8n_list_workflows',
                        'description' => 'List available n8n workflows',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'active' => ['type' => 'boolean', 'description' => 'Filter to only active workflows'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'n8n_get_executions',
                        'description' => 'Get recent workflow execution history',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'workflow_id' => ['type' => 'string', 'description' => 'Filter by workflow ID (optional)'],
                                'limit' => ['type' => 'integer', 'description' => 'Max executions to return (default: 20)'],
                            ],
                        ],
                    ],
                ],
                'settings' => ['timeout' => 60],
            ],
        ];
    }
}
