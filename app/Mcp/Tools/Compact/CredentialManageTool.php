<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Credential\CredentialCreateTool;
use App\Mcp\Tools\Credential\CredentialDeleteTool;
use App\Mcp\Tools\Credential\CredentialGetTool;
use App\Mcp\Tools\Credential\CredentialListTool;
use App\Mcp\Tools\Credential\CredentialOAuthFinalizeTool;
use App\Mcp\Tools\Credential\CredentialOAuthInitiateTool;
use App\Mcp\Tools\Credential\CredentialRotateTool;
use App\Mcp\Tools\Credential\CredentialUpdateTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class CredentialManageTool extends CompactTool
{
    protected string $name = 'credential_manage';

    protected string $description = <<<'TXT'
Encrypted credential vault for external services (API keys, OAuth2 tokens, basic auth, bearer tokens). Secrets are encrypted at rest with the team's per-tenant key; `secret_data` is never returned by `get` — only metadata (name, type, expires_at, last_rotated_at).

Actions:
- list (read) — optional: type, status filter.
- get (read) — credential_id. Metadata only, secrets redacted.
- create (write) — name, type (api_key/oauth2/basic_auth/bearer_token/custom), secret_data (object).
- update (write) — credential_id + any creatable field.
- delete (DESTRUCTIVE) — credential_id. Hard delete; not recoverable.
- rotate (write) — credential_id, new_secret_data. Bumps `last_rotated_at` and re-encrypts.
- oauth_initiate (write) — provider, scopes[]. Returns authorization URL.
- oauth_finalize (write) — provider, code (from OAuth callback). Stores tokens, returns credential_id.
TXT;

    protected function toolMap(): array
    {
        return [
            'list' => CredentialListTool::class,
            'get' => CredentialGetTool::class,
            'create' => CredentialCreateTool::class,
            'update' => CredentialUpdateTool::class,
            'delete' => CredentialDeleteTool::class,
            'rotate' => CredentialRotateTool::class,
            'oauth_initiate' => CredentialOAuthInitiateTool::class,
            'oauth_finalize' => CredentialOAuthFinalizeTool::class,
        ];
    }
}
