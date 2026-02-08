<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillVersion;
use Illuminate\Support\Facades\DB;

class UpdateSkillAction
{
    public function execute(
        Skill $skill,
        array $attributes = [],
        ?string $changelog = null,
        ?string $updatedBy = null,
    ): Skill {
        return DB::transaction(function () use ($skill, $attributes, $changelog, $updatedBy) {
            // Determine if schema/config changed (triggers version bump)
            $schemaChanged = isset($attributes['input_schema']) || isset($attributes['output_schema']);
            $configChanged = isset($attributes['configuration']);
            $needsVersionBump = $schemaChanged || $configChanged;

            $skill->update($attributes);

            if ($needsVersionBump) {
                $newVersion = $this->bumpVersion($skill->current_version);

                SkillVersion::create([
                    'skill_id' => $skill->id,
                    'version' => $newVersion,
                    'input_schema' => $skill->input_schema ?? [],
                    'output_schema' => $skill->output_schema ?? [],
                    'configuration' => $skill->configuration ?? [],
                    'changelog' => $changelog ?? 'Updated schema/configuration',
                    'created_by' => $updatedBy,
                ]);

                $skill->update(['current_version' => $newVersion]);
            }

            return $skill->fresh();
        });
    }

    private function bumpVersion(string $currentVersion): string
    {
        $parts = explode('.', $currentVersion);
        $parts[2] = ((int) ($parts[2] ?? 0)) + 1;

        return implode('.', $parts);
    }
}
