#!/usr/bin/env node

/**
 * FleetQ MCP Gateway
 *
 * A stdio MCP server that proxies all requests to a running FleetQ instance
 * via its HTTP/SSE MCP endpoint. This allows any MCP client (Claude Desktop,
 * Cursor, etc.) to connect to FleetQ without running the full stack locally.
 *
 * Required environment variables:
 *   FLEETQ_URL    - Base URL of your FleetQ instance (e.g. https://fleetq.net)
 *   FLEETQ_TOKEN  - Sanctum API token from Settings → API Tokens
 */

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import {
  ListToolsRequestSchema,
  CallToolRequestSchema,
  ListResourcesRequestSchema,
  ReadResourceRequestSchema,
  ListPromptsRequestSchema,
  GetPromptRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';

const FLEETQ_URL = process.env.FLEETQ_URL;
const FLEETQ_TOKEN = process.env.FLEETQ_TOKEN;

if (!FLEETQ_URL) {
  process.stderr.write('Error: FLEETQ_URL environment variable is required.\n');
  process.exit(1);
}

if (!FLEETQ_TOKEN) {
  process.stderr.write('Error: FLEETQ_TOKEN environment variable is required.\n');
  process.exit(1);
}

const mcpUrl = new URL('/mcp', FLEETQ_URL.replace(/\/$/, ''));

// Connect upstream client to FleetQ HTTP MCP endpoint
const upstreamClient = new Client(
  { name: 'fleetq-mcp-gateway', version: '1.0.0' },
  { capabilities: { tools: {}, resources: {}, prompts: {} } },
);

const httpTransport = new StreamableHTTPClientTransport(mcpUrl, {
  requestInit: {
    headers: {
      Authorization: `Bearer ${FLEETQ_TOKEN}`,
      Accept: 'application/json, text/event-stream',
    },
  },
});

try {
  await upstreamClient.connect(httpTransport);
} catch (err) {
  process.stderr.write(`Error: Failed to connect to FleetQ at ${mcpUrl}\n${err.message}\n`);
  process.exit(1);
}

// Create stdio server that proxies all requests upstream
const server = new Server(
  { name: 'fleetq', version: '1.0.0' },
  { capabilities: { tools: {}, resources: {}, prompts: {} } },
);

server.setRequestHandler(ListToolsRequestSchema, async (request) => {
  return upstreamClient.listTools(request.params);
});

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  return upstreamClient.callTool(request.params);
});

server.setRequestHandler(ListResourcesRequestSchema, async (request) => {
  return upstreamClient.listResources(request.params);
});

server.setRequestHandler(ReadResourceRequestSchema, async (request) => {
  return upstreamClient.readResource(request.params);
});

server.setRequestHandler(ListPromptsRequestSchema, async (request) => {
  return upstreamClient.listPrompts(request.params);
});

server.setRequestHandler(GetPromptRequestSchema, async (request) => {
  return upstreamClient.getPrompt(request.params);
});

const stdioTransport = new StdioServerTransport();
await server.connect(stdioTransport);
