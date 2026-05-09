<?php

declare(strict_types=1);

namespace App\Domain\Crew\Actions;

use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reorders worker members of a crew based on a flat array of ordered IDs.
 *
 * Idempotent. Validates that every ID belongs to the crew (no cross-crew
 * leakage). Skips coordinator/QA — only Worker-role members are reorderable.
 */
class ReorderCrewMembersAction
{
    /**
     * @param  array<int, string>  $orderedMemberIds  IDs in desired order
     *
     * @throws InvalidArgumentException when an ID does not belong to the crew
     */
    public function execute(Crew $crew, array $orderedMemberIds): void
    {
        if ($orderedMemberIds === []) {
            return;
        }

        $existing = $crew->members()
            ->whereIn('id', $orderedMemberIds)
            ->where('role', CrewMemberRole::Worker)
            ->pluck('id')
            ->all();

        $missing = array_diff($orderedMemberIds, $existing);
        if (! empty($missing)) {
            throw new InvalidArgumentException(
                'Some members do not belong to this crew or are not workers: '
                .implode(', ', $missing),
            );
        }

        DB::transaction(function () use ($orderedMemberIds): void {
            foreach ($orderedMemberIds as $index => $memberId) {
                CrewMember::where('id', $memberId)->update(['sort_order' => $index]);
            }
        });
    }
}
