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

    protected string $description = 'Manage external service credentials. Actions: list, get (credential_id), create (name, type, secret_data), update (credential_id + fields), delete (credential_id), rotate (credential_id), oauth_initiate (provider, scopes), oauth_finalize (provider, code).';

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
