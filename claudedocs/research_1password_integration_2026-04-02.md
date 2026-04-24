# 1Password Integration Research for FleetQ

**Date**: 2026-04-02
**Author**: Research Agent (Claude Opus 4.6)
**Status**: Complete

---

## Executive Summary

1Password offers four primary integration paths for developer platforms: **Service Accounts** (token-based, zero infrastructure), **Connect Server** (self-hosted REST API), **SDKs** (Go, JavaScript, Python — no PHP), and a **CLI** tool. There is no official 1Password MCP server, but a well-maintained community MCP server exists (`@takescake/1password-mcp`) that uses the 1Password SDK under the hood. The OAuth 2.0 flow available from 1Password is limited to the **Users API for Partners** (user management only, not secrets access) and uses **client credentials grant** (server-to-server), not an authorization code flow suitable for end-user BYOK connection.

**Recommended approach for FleetQ**: Each team stores their 1Password **Service Account Token** in FleetQ's existing Credential domain (encrypted with per-team envelope encryption). FleetQ resolves secrets at runtime via the Connect REST API or by shelling out to `op` CLI in Horizon workers. For the MCP integration, register the community 1Password MCP server as an available Tool in the Tool domain.

---

## 1. Available Integration Methods

### 1.1 Service Accounts (Recommended for FleetQ BYOK)

- **What**: Non-human identity with a bearer token (`OP_SERVICE_ACCOUNT_TOKEN`) scoped to specific vaults and permissions.
- **Auth**: Single token string, set as environment variable. No infrastructure to deploy.
- **Capabilities**: Create, fetch, edit, delete, share items; read environment variables from 1Password Environments; create/delete vaults; retrieve user/group info.
- **Rate Limits**: Yes — subject to 1Password rate limits and request quotas.
- **Best for**: Cloud/SaaS platforms where each team brings their own 1Password token.
- **Limitation**: Cannot access Personal, Private, Employee, or default Shared vaults. Permissions are immutable after creation (must recreate to change).
- **Max**: 100 service accounts per 1Password account.

**How it works for FleetQ BYOK**:
1. User creates a Service Account in their 1Password Business/Teams account
2. User grants it access to the vaults containing their API keys/secrets
3. User pastes the `OP_SERVICE_ACCOUNT_TOKEN` into FleetQ's Credential creation UI
4. FleetQ stores it encrypted via `CredentialEncryption` (per-team DEK)
5. At runtime, FleetQ uses the token to resolve secrets via SDK or CLI

### 1.2 Connect Server (Self-Hosted REST API)

- **What**: Two Docker containers (`1password/connect-api` + `1password/connect-sync`) deployed in your infrastructure.
- **Auth**: Access token generated during setup + `1password-credentials.json` file.
- **REST API**: Full CRUD on items and vaults via HTTP (e.g., `GET /v1/vaults`, `GET /v1/vaults/{id}/items`).
- **Rate Limits**: None (data cached locally, unlimited re-requests).
- **SDKs**: Go, Python, JavaScript Connect SDKs available.
- **Best for**: High-throughput, low-latency secret access where you control infrastructure.
- **Limitation**: Requires Docker/Kubernetes infrastructure per deployment. Each Connect server needs its own credentials file.

**FleetQ relevance**: Could be deployed alongside FleetQ's Docker stack for platform-level secret management, but NOT suitable for per-team BYOK since each team would need their own Connect server instance.

### 1.3 Official SDKs

| Language | Package | Auth Methods |
|----------|---------|-------------|
| **Go** | `github.com/1Password/onepassword-sdk-go` | Service Account Token, Desktop App (biometric) |
| **JavaScript** | `@1password/sdk` (npm) | Service Account Token, Desktop App (biometric) |
| **Python** | `onepassword-sdk` (PyPI) | Service Account Token, Desktop App (biometric) |

**No PHP SDK exists.** For Laravel/FleetQ, options are:
1. **HTTP calls to Connect Server REST API** (if self-hosted)
2. **Shell out to `op` CLI** from Horizon workers (proc_open available in CLI context)
3. **Node.js microservice** wrapping the JavaScript SDK
4. **Direct REST API calls** to Connect Server endpoints

**SDK Functionality** (all three languages):
- Item CRUD (create, read, update, delete, archive, list, bulk operations)
- Secret resolution via references (`op://vault/item/field`)
- Vault CRUD (create, read, update, delete, list)
- Group vault permissions management
- Password generation
- Item sharing
- File attachments and Document items
- OTP retrieval
- SSH key management

### 1.4 CLI (`op`)

- **Install**: Available for macOS, Linux, Windows via package managers.
- **Auth**: Service Account Token (via `OP_SERVICE_ACCOUNT_TOKEN` env var) or interactive sign-in.
- **Key Commands**:
  - `op item get <item> --vault <vault> --fields label=password` — retrieve a secret
  - `op item create --category=login --vault=<vault> --title=<title>` — create items
  - `op inject -i template.env -o .env` — inject secrets into config files
  - `op run --env-file=.env -- command` — run command with secrets injected
- **Secret References**: `op://vault-name/item-name/field-name` syntax for referencing secrets.
- **Shell Plugins**: Authenticate third-party CLIs (AWS, GitHub, etc.) with biometrics.

**FleetQ relevance**: The CLI is the most practical PHP integration path. Horizon workers have `proc_open` enabled and can execute `op` commands with the team's Service Account Token injected as an env var.

### 1.5 Community MCP Server

- **Package**: `@takescake/1password-mcp` (npm)
- **GitHub**: [CakeRepository/1Password-MCP](https://github.com/CakeRepository/1Password-MCP)
- **Auth**: `OP_SERVICE_ACCOUNT_TOKEN` environment variable or `--service-account-token` CLI flag
- **Transport**: stdio (standard MCP)
- **Tools (8)**:

| Tool | Description |
|------|-------------|
| `vault_list` | List all accessible vaults |
| `item_lookup` | Search items by title in a vault |
| `item_delete` | Delete an item from a vault |
| `password_create` | Create a new password/login item |
| `password_read` | Retrieve a password via secret reference or vault/item ID |
| `password_update` | Rotate/update an existing password |
| `password_generate` | Generate a cryptographically secure random password |
| `password_generate_memorable` | Generate a memorable passphrase |

**FleetQ relevance**: Can be registered as an MCP stdio Tool in the Tool domain. When an agent needs secrets, FleetQ resolves the team's Service Account Token from the Credential domain and injects it as `OP_SERVICE_ACCOUNT_TOKEN` into the MCP server process environment.

---

## 2. Authentication Flows

### 2.1 Service Account Token (Primary — for secrets access)

```
User creates Service Account in 1Password.com
  → Selects vaults and permissions
  → Gets token string (e.g., "ops_eyJ...")
  → Stores in FleetQ Credential (encrypted)
  
At runtime:
  FleetQ decrypts token → sets OP_SERVICE_ACCOUNT_TOKEN → calls op CLI or SDK
```

- **Token format**: Opaque string, starts with `ops_`
- **Rotation**: Create new service account, update token in FleetQ, delete old one
- **Scope**: Per-vault, per-permission (read/write/create/delete)

### 2.2 OAuth 2.0 Client Credentials (Users API only — NOT for secrets)

- **Flow**: OAuth 2.0 Client Credentials Grant (RFC 6749 Section 4.4)
- **Purpose**: User management only (list, get, suspend, reactivate users)
- **Requirements**: 1Password Business tier (Enterprise Password Manager)
- **Token endpoint**: `POST /v1beta1/users/oauth2/token`
- **Token TTL**: 15 minutes (900 seconds)
- **Scopes**: `list_users`, `get_user`, `suspend_user`, `reactivate_user`

**Important**: This OAuth flow does NOT provide access to vaults or secrets. It is purely for user/account management automation. It is NOT suitable for a BYOK "Connect your 1Password" flow.

### 2.3 Connect Server Token (Self-hosted REST API)

```
Admin creates Connect server workflow on 1Password.com
  → Downloads 1password-credentials.json
  → Generates access token (OP_API_TOKEN)
  → Deploys Docker containers with credentials file
  
REST API available at http://localhost:8080
  Authorization: Bearer <OP_API_TOKEN>
```

### 2.4 No End-User OAuth Authorization Code Flow

**Critical finding**: 1Password does NOT offer an OAuth Authorization Code or PKCE flow where end users can "Connect their 1Password account" via a redirect-based flow (like you would with GitHub, Google, or Slack). The OAuth 2.0 support is strictly:
- Client Credentials Grant for the Users API (server-to-server, Business tier only)
- No authorization code flow
- No PKCE flow
- No user consent screen for vault access

This means **the BYOK pattern for 1Password must use Service Account Tokens**, not OAuth.

---

## 3. FleetQ Integration Recommendations

### 3.1 Credential Domain Integration (Priority: High)

Add a new `CredentialType::ONEPASSWORD_SERVICE_ACCOUNT` enum value:

```php
// app/Domain/Credential/Enums/CredentialType.php
case ONEPASSWORD_SERVICE_ACCOUNT = 'onepassword_service_account';
```

**Credential storage schema** (in `secret_data` JSONB, encrypted with team DEK):
```json
{
  "service_account_token": "ops_eyJ...",
  "default_vault": "Engineering Secrets"
}
```

### 3.2 Secret Resolution Service (Priority: High)

Create an `OnePasswordResolver` that retrieves secrets at runtime:

```php
// app/Infrastructure/Secrets/OnePasswordResolver.php
class OnePasswordResolver
{
    /**
     * Resolve a 1Password secret reference.
     * Uses `op` CLI with service account token.
     * 
     * @param string $reference e.g. "op://vault/item/field"
     * @param string $serviceAccountToken
     * @return string The resolved secret value
     */
    public function resolve(string $reference, string $serviceAccountToken): string
    {
        // Execute: OP_SERVICE_ACCOUNT_TOKEN=<token> op read <reference>
        // Only in Horizon workers (proc_open available)
    }
    
    /**
     * Resolve multiple secrets in one call.
     */
    public function resolveMany(array $references, string $serviceAccountToken): array
    {
        // Execute: op inject with template
    }
}
```

### 3.3 Tool Domain Integration (Priority: Medium)

Register the community MCP server as a pre-configured Tool template:

```php
// Tool creation seed/factory
[
    'name' => '1Password Vault',
    'type' => ToolType::MCP_STDIO,
    'command' => 'npx',
    'args' => ['-y', '@takescake/1password-mcp'],
    'env_mapping' => [
        'OP_SERVICE_ACCOUNT_TOKEN' => 'credential:onepassword_service_account.service_account_token'
    ],
]
```

When an agent execution starts:
1. `ResolveAgentTools` finds the 1Password MCP tool
2. `ToolTranslator` maps credential references to env vars
3. The MCP server process launches with the team's token injected

### 3.4 Credential Auto-Resolution for Agent Execution (Priority: Medium)

Extend `LocalBridgeGateway::resolveAgentCredentialEnv()` to support 1Password references:

Instead of storing raw API keys in FleetQ, a Credential could store:
```json
{
  "type": "onepassword_reference",
  "service_account_credential_id": "uuid-of-1p-credential",
  "reference": "op://DevOps/Stripe API Key/credential"
}
```

At execution time, FleetQ resolves the reference via the `OnePasswordResolver` and injects the actual secret. This keeps secrets in 1Password as the source of truth.

### 3.5 Cloud UI: "Connect 1Password" Flow (Priority: Low)

Since there is no OAuth redirect flow, the UX would be:

1. **Instructions page**: Guide user to create a Service Account in 1Password
2. **Token input**: Secure paste field for the Service Account Token
3. **Validation**: Call `op vault list` with the token to verify it works
4. **Vault browser**: Show accessible vaults and items (read-only preview)
5. **Mapping UI**: Let users map 1Password items to FleetQ Credentials

### 3.6 Integration Page Entry

Add to the Integration domain as a first-class integration:

```
Name: 1Password
Category: Secrets Management
Auth Type: Service Account Token
Capabilities:
  - Secret resolution (op:// references)
  - Credential auto-sync
  - MCP tool for agents
  - Vault browsing
```

---

## 4. Architecture Decision: CLI vs Connect Server vs Node Microservice

| Approach | Pros | Cons | Recommendation |
|----------|------|------|----------------|
| **op CLI** | Simple, no extra services, works with service account tokens | Requires CLI installed in Docker image, proc_open only in CLI context | **Primary** — install `op` in Docker image |
| **Connect Server** | Full REST API, no rate limits, low latency | Per-team instances impractical, infrastructure overhead | **Platform-level only** (not BYOK) |
| **Node.js microservice** | Uses official JS SDK, clean REST wrapper | Extra service to maintain, deployment complexity | **Future option** if CLI proves insufficient |
| **Direct HTTP to 1P API** | No CLI dependency | No public REST API for secrets (only Connect) | **Not viable** for secrets |

**Decision**: Install `op` CLI v2.x in the Docker image. Use it from Horizon workers with `OP_SERVICE_ACCOUNT_TOKEN` per-team injection. This aligns with FleetQ's existing pattern of process execution in queue workers.

---

## 5. Security Considerations

1. **Token storage**: Service Account Tokens must be stored with per-team envelope encryption (existing `CredentialEncryption` with XSalsa20-Poly1305).
2. **Token scope**: Encourage users to create narrowly-scoped service accounts (read-only access to specific vaults).
3. **Token rotation**: Provide a UI to rotate/replace the stored token. 1Password service account tokens are immutable — rotation means creating a new service account.
4. **Audit trail**: Log every 1Password secret resolution via `CredentialEncryption::logAccess()`.
5. **No token in logs**: Ensure `OP_SERVICE_ACCOUNT_TOKEN` is redacted in all log output.
6. **Rate limiting**: Service accounts have rate limits. Cache resolved secrets in Redis (encrypted) with short TTL to avoid hitting limits during burst agent executions.

---

## 6. Comparison: 1Password vs Alternatives

| Feature | 1Password | HashiCorp Vault | AWS Secrets Manager | Doppler |
|---------|-----------|-----------------|---------------------|---------|
| OAuth BYOK flow | No (token only) | No | IAM roles | Yes (OAuth) |
| PHP SDK | No | Yes | Yes (AWS SDK) | Yes |
| MCP Server | Community | Community | No | No |
| Self-hosted option | Connect Server | Yes | No (AWS only) | No |
| Free tier | No | Open Source | Pay-per-use | Free plan |
| Secret references | `op://vault/item/field` | `vault:secret/path#key` | ARN | `DOPPLER_PROJECT` |

---

## Sources

1. [1Password SDKs Overview](https://developer.1password.com/docs/sdks/) — Official SDK docs (Go, JS, Python)
2. [1Password SDK Supported Functionality](https://developer.1password.com/docs/sdks/functionality) — Complete feature matrix
3. [1Password Connect Server](https://developer.1password.com/docs/connect/) — Self-hosted REST API
4. [1Password Connect Get Started](https://developer.1password.com/docs/connect/get-started/) — Docker/K8s deployment
5. [1Password Service Accounts](https://developer.1password.com/docs/service-accounts/) — Token-based automation
6. [Service Accounts Get Started](https://developer.1password.com/docs/service-accounts/get-started/) — Setup guide
7. [1Password Secrets Automation](https://developer.1password.com/docs/secrets-automation/) — Comparison table (SA vs Connect)
8. [1Password CLI](https://developer.1password.com/docs/cli/) — Command-line tool
9. [1Password SDK Load Secrets](https://developer.1password.com/docs/sdks/load-secrets) — Secret reference syntax
10. [1Password Users API (OAuth 2.0)](https://developer.1password.com/docs/users-api/) — Partners API (user management only)
11. [OAuth 2.0 Authorization](https://developer.1password.com/docs/users-api/authorization) — Client credentials flow details
12. [CakeRepository/1Password-MCP](https://github.com/CakeRepository/1Password-MCP) — Community MCP server (8 tools)
13. [1Password Developer Security](https://1password.com/developer-security) — Developer portal landing page
