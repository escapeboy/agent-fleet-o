<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Artifact\ArtifactContentTool;
use App\Mcp\Tools\Artifact\ArtifactDownloadTool;
use App\Mcp\Tools\Artifact\ArtifactGetTool;
use App\Mcp\Tools\Artifact\ArtifactListTool;

class ArtifactManageTool extends CompactTool
{
    protected string $name = 'artifact_manage';

    protected string $description = 'Manage experiment/workflow artifacts. Actions: list (experiment_id or project_run_id), get (artifact_id), content (artifact_id, version), download (artifact_id — returns download URL).';

    protected function toolMap(): array
    {
        return [
            'list' => ArtifactListTool::class,
            'get' => ArtifactGetTool::class,
            'content' => ArtifactContentTool::class,
            'download' => ArtifactDownloadTool::class,
        ];
    }
}
