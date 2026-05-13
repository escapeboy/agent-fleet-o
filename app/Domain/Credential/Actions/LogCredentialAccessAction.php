<?php

namespace App\Domain\Credential\Actions;

use App\Domain\Credential\Models\Credential;
use App\Domain\Credential\Models\CredentialAccessLog;

class LogCredentialAccessAction
{
    public function execute(
        Credential $credential,
        string $resolvedFor,
        ?string $targetDomain = null,
        bool $allowed = true,
        ?string $agentId = null,
        ?string $toolId = null,
    ): void {
        CredentialAccessLog::create([
            'credential_id' => $credential->id,
            'team_id' => $credential->team_id,
            'agent_id' => $agentId,
            'tool_id' => $toolId,
            'resolved_for' => $resolvedFor,
            'target_domain' => $targetDomain,
            'allowed' => $allowed,
        ]);
    }
}
