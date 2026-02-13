<?php

namespace Database\Seeders;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Illuminate\Database\Seeder;

class PopularToolsSeeder extends Seeder
{
    public function run(): void
    {
        $team = Team::first();

        if (! $team) {
            $this->command?->warn('No team found. Run app:install first.');

            return;
        }

        $definitions = $this->toolDefinitions();
        $created = 0;
        $skipped = 0;

        foreach ($definitions as $def) {
            $tool = Tool::withoutGlobalScopes()->updateOrCreate(
                ['team_id' => $team->id, 'slug' => $def['slug']],
                [
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'type' => $def['type'],
                    'status' => ToolStatus::Disabled,
                    'transport_config' => $def['transport_config'],
                    'tool_definitions' => $def['tool_definitions'],
                    'settings' => $def['settings'],
                ]
            );

            if ($tool->wasRecentlyCreated) {
                $created++;
            } else {
                $skipped++;
            }
        }

        $this->command?->info("Tools: {$created} created, {$skipped} already existed.");
    }

    private function toolDefinitions(): array
    {
        return [
            // ─── Built-in Tools ─────────────────────────────────────

            [
                'name' => 'Bash Shell',
                'slug' => 'bash-shell',
                'description' => 'Execute shell commands in a sandboxed environment. Supports common CLI tools like curl, jq, python3, node, grep, awk, sed, and more. Commands are restricted to an allowed list and paths are sandboxed.',
                'type' => ToolType::BuiltIn,
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
        ];
    }
}
