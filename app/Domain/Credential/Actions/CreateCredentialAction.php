<?php

namespace App\Domain\Credential\Actions;

use App\Domain\Approval\Actions\CreateCredentialApprovalRequestAction;
use App\Domain\Credential\Enums\CredentialSource;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use Illuminate\Support\Str;

class CreateCredentialAction
{
    public function execute(
        string $teamId,
        string $name,
        CredentialType $credentialType,
        array $secretData,
        ?string $description = null,
        array $metadata = [],
        ?string $expiresAt = null,
        CredentialSource $creatorSource = CredentialSource::Human,
        ?string $creatorType = null,
        ?string $creatorId = null,
    ): Credential {
        $status = $creatorSource === CredentialSource::Human
            ? CredentialStatus::Active
            : CredentialStatus::PendingReview;

        $credential = Credential::withoutGlobalScopes()->create([
            'team_id' => $teamId,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $description,
            'credential_type' => $credentialType,
            'status' => $status,
            'secret_data' => $secretData,
            'metadata' => $metadata,
            'expires_at' => $expiresAt,
            'creator_source' => $creatorSource,
            'creator_type' => $creatorType,
            'creator_id' => $creatorId,
        ]);

        if ($creatorSource !== CredentialSource::Human) {
            app(CreateCredentialApprovalRequestAction::class)->execute($credential);
        }

        return $credential;
    }
}
