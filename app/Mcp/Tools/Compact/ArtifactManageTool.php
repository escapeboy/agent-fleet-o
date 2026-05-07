<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Artifact\ArtifactContentTool;
use App\Mcp\Tools\Artifact\ArtifactDownloadTool;
use App\Mcp\Tools\Artifact\ArtifactGetTool;
use App\Mcp\Tools\Artifact\ArtifactListTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ArtifactManageTool extends CompactTool
{
    protected string $name = 'artifact_manage';

    protected string $description = <<<'TXT'
Read-only access to artifacts produced by experiments, crew executions, and project runs (code, documents, data, media). Each artifact has 1..N `ArtifactVersion` entries; `version` defaults to latest when omitted. `download` returns a signed URL valid for ~5 minutes; `content` returns the body inline (base64 for binary MIMEs).

Actions:
- list (read) — exactly one of: experiment_id, crew_execution_id, project_run_id; optional limit.
- get (read) — artifact_id. Metadata only (size, mime, version count, created_by AiRun id).
- content (read) — artifact_id; optional version. Returns raw or base64 body.
- download (read) — artifact_id; optional version. Returns short-lived signed URL.
TXT;

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
