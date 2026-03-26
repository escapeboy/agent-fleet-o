<?php

declare(strict_types=1);

namespace App\Domain\GitRepository\Services;

use App\Domain\GitRepository\Models\CodeElement;
use Illuminate\Support\Collection;

/**
 * Returns a signatures-only view of all code elements in a given file.
 *
 * This is the "code skimming" primitive from the HKUDS paper: instead of reading
 * full file content, the agent sees only the structural outline (names, types,
 * line numbers, signatures). Full source is fetched on demand via GitClientInterface.
 */
class CodeSkimmingService
{
    /**
     * Return all indexed code elements for the given file path, ordered by line number.
     * Only structural fields are selected — embedding and full docstring are excluded
     * to keep the context window small.
     *
     * @return Collection<int, CodeElement>
     */
    public function skimFile(string $teamId, string $repositoryId, string $filePath): Collection
    {
        return CodeElement::select(['id', 'element_type', 'name', 'file_path', 'line_start', 'line_end', 'signature'])
            ->where('team_id', $teamId)
            ->where('git_repository_id', $repositoryId)
            ->where('file_path', $filePath)
            ->where('element_type', '!=', 'file')
            ->orderBy('line_start')
            ->get();
    }
}
