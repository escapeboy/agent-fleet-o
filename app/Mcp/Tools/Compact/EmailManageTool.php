<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Email\EmailTemplateCreateTool;
use App\Mcp\Tools\Email\EmailTemplateDeleteTool;
use App\Mcp\Tools\Email\EmailTemplateGenerateTool;
use App\Mcp\Tools\Email\EmailTemplateGetTool;
use App\Mcp\Tools\Email\EmailTemplateListTool;
use App\Mcp\Tools\Email\EmailTemplateUpdateTool;
use App\Mcp\Tools\Email\EmailThemeCreateTool;
use App\Mcp\Tools\Email\EmailThemeDeleteTool;
use App\Mcp\Tools\Email\EmailThemeGetTool;
use App\Mcp\Tools\Email\EmailThemeListTool;
use App\Mcp\Tools\Email\EmailThemeUpdateTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class EmailManageTool extends CompactTool
{
    protected string $name = 'email_manage';

    protected string $description = <<<'TXT'
Email themes (visual styling) and templates (transactional + marketing copy). Templates can be hand-written MJML/HTML or AI-generated via `template_generate` (consumes credits). Deleting a theme used by templates is blocked unless those templates are deleted first.

Theme actions:
- theme_list / theme_get (read).
- theme_create (write) — name, styles (object: colors, fonts, spacing).
- theme_update (write) — theme_id + any creatable field.
- theme_delete (DESTRUCTIVE) — theme_id. Fails if any template references it.

Template actions:
- template_list / template_get (read).
- template_create (write) — name, subject, body (MJML/HTML); optional theme_id.
- template_update (write) — template_id + any creatable field.
- template_delete (DESTRUCTIVE) — template_id.
- template_generate (write — costs credits) — prompt. Calls the team's default LLM to produce a template, returns draft for review.
TXT;

    protected function toolMap(): array
    {
        return [
            'theme_list' => EmailThemeListTool::class,
            'theme_get' => EmailThemeGetTool::class,
            'theme_create' => EmailThemeCreateTool::class,
            'theme_update' => EmailThemeUpdateTool::class,
            'theme_delete' => EmailThemeDeleteTool::class,
            'template_list' => EmailTemplateListTool::class,
            'template_get' => EmailTemplateGetTool::class,
            'template_create' => EmailTemplateCreateTool::class,
            'template_update' => EmailTemplateUpdateTool::class,
            'template_delete' => EmailTemplateDeleteTool::class,
            'template_generate' => EmailTemplateGenerateTool::class,
        ];
    }
}
