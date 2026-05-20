<?php

declare(strict_types=1);

namespace App\Domain\Release\Actions;

use App\Domain\Release\Enums\ReleaseStatus;
use App\Domain\Release\Models\Release;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateReleaseAction
{
    /**
     * @throws InvalidArgumentException when (team_id, slug, version) collides
     */
    public function execute(
        string $teamId,
        ?string $userId,
        string $name,
        string $version,
        ?string $notes = null,
        array $metadata = [],
    ): Release {
        $name = trim($name);
        $version = trim($version);

        if ($name === '' || $version === '') {
            throw new InvalidArgumentException('Release name and version are required.');
        }

        $slug = Str::slug($name);
        if ($slug === '') {
            $slug = 'release';
        }

        try {
            return Release::create([
                'team_id' => $teamId,
                'user_id' => $userId,
                'name' => $name,
                'slug' => $slug,
                'version' => $version,
                'notes' => $notes,
                'status' => ReleaseStatus::Draft,
                'metadata' => $metadata,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new InvalidArgumentException(
                    "A release with name '{$name}' and version '{$version}' already exists.",
                );
            }

            throw $e;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = $e->getCode();

        return $code === '23505' || $code === '23000';
    }
}
