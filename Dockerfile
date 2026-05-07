# FleetQ MCP Wrapper — bridges stdio MCP (Glama, Claude Desktop, Codex,
# any stdio MCP client) to a hosted FleetQ instance's HTTP/SSE endpoint.
#
# Uses `mcp-remote` so the user's existing Sanctum API token authenticates
# every tool call against their real team data — no embedded SQLite, no
# duplicate state.
#
# Build:   docker build -t fleetq/mcp .
# Run:     docker run --rm -i \
#            -e FLEETQ_URL=https://fleetq.net \
#            -e FLEETQ_TOKEN=<sanctum_token> \
#            fleetq/mcp

FROM node:20-alpine

# `mcp-remote` is the canonical Node CLI for bridging stdio ↔ HTTP/SSE MCP.
# Pinning a major version keeps Glama's deterministic builds reproducible.
RUN npm install -g mcp-remote@latest \
 && rm -rf /root/.npm /tmp/*

# Default to the public FleetQ Cloud endpoint; override with --env
# FLEETQ_URL=https://your-fleetq.example.com for self-hosted installs.
ENV FLEETQ_URL=https://fleetq.net

LABEL org.opencontainers.image.title="FleetQ MCP Server" \
      org.opencontainers.image.description="stdio→HTTP/SSE wrapper for the FleetQ /mcp endpoint (33 consolidated tools across agents, workflows, experiments, signals, projects, marketplace, budgets, approvals, and more)" \
      org.opencontainers.image.source="https://github.com/escapeboy/agent-fleet-o" \
      org.opencontainers.image.licenses="AGPL-3.0" \
      org.opencontainers.image.vendor="FleetQ"

ENTRYPOINT ["/bin/sh", "-c"]
# Refuse to start without an API token rather than silently producing
# unauthenticated requests. Sanctum tokens are minted at /team → API Tokens.
CMD ["if [ -z \"${FLEETQ_TOKEN}\" ]; then \
        echo 'ERROR: FLEETQ_TOKEN is required (Sanctum API token — see Settings → API Tokens in FleetQ).' >&2; \
        echo 'Example: docker run --rm -i -e FLEETQ_URL=https://fleetq.net -e FLEETQ_TOKEN=xxx fleetq/mcp' >&2; \
        exit 1; \
     fi; \
     exec npx --yes mcp-remote \"${FLEETQ_URL}/mcp\" --header \"Authorization: Bearer ${FLEETQ_TOKEN}\""]
