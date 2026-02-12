<?php

namespace App\Domain\Credential\Actions;

use App\Domain\Credential\Models\Credential;
use App\Domain\Project\Models\Project;

class DeleteCredentialAction
{
    public function execute(Credential $credential): void
    {
        // Remove this credential from all project allowed_credential_ids arrays
        Project::withoutGlobalScopes()
            ->whereJsonContains('allowed_credential_ids', $credential->id)
            ->each(function (Project $project) use ($credential) {
                $ids = array_values(array_filter(
                    $project->allowed_credential_ids ?? [],
                    fn ($id) => $id !== $credential->id,
                ));
                $project->update(['allowed_credential_ids' => $ids]);
            });

        $credential->delete();
    }
}
