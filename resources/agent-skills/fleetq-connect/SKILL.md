---
name: fleetq-connect
description: Connect an agent to FleetQ over MCP or the REST API, including obtaining credentials via dynamic client registration.
---

# Connect to FleetQ

FleetQ exposes every platform capability to agents. Use this skill to
establish a working connection before calling any other FleetQ skill.

## Pick a transport

| Transport | Endpoint | Use when |
|---|---|---|
| MCP over HTTP/SSE | `POST {{APP_URL}}/mcp` | Your runtime speaks Model Context Protocol. Preferred — you get typed tools for the whole platform. |
| REST API v1 | `{{APP_URL}}/api/v1` | You want plain HTTP. Described by [OpenAPI 3.1]({{APP_URL}}/docs/api.json). |

Both use bearer tokens in the `Authorization` header.

## Get a credential

FleetQ supports RFC 7591 dynamic client registration, so an agent can
self-provision without human setup.

1. Fetch authorization server metadata:
   `GET {{APP_URL}}/.well-known/oauth-authorization-server`
2. `POST` your client metadata to the advertised `registration_endpoint`
   (`{{APP_URL}}/oauth/register`). Rate limit: 20 requests per hour per IP.
3. Run the authorization-code flow with PKCE (`S256`) against the
   `authorization_endpoint`, requesting the `mcp:use` scope.
4. Exchange the code at the `token_endpoint` for an access token.

Full detail: [{{APP_URL}}/auth.md]({{APP_URL}}/auth.md)

Alternatively, a human operator can mint a Sanctum API token from
**Team settings → API tokens** and pass it to you directly.

## Verify the connection

```
GET {{APP_URL}}/api/v1/health
```

Returns platform health without authentication — use it to confirm
reachability before debugging credentials.

Then confirm your token works:

```
GET {{APP_URL}}/api/v1/me
Authorization: Bearer <access_token>
```

A `401` means the token is missing, expired, or lacks the `mcp:use` scope.

## Discover what you can do

- `{{APP_URL}}/.well-known/fleetq` — machine-readable discovery document.
- `{{APP_URL}}/.well-known/api-catalog` — RFC 9727 catalog of API surfaces.
- `{{APP_URL}}/llms.txt` — compact platform index.
- Over MCP: call `tools/list` to enumerate the available tools directly.

## Notes

- All data is team-scoped. A token carries exactly one team's permissions
  and role; you cannot read across teams.
- Write and destructive operations are role-gated. A viewer-role token can
  read but not mutate.
