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

class EmailManageTool extends CompactTool
{
    protected string $name = 'email_manage';

    protected string $description = 'Manage email themes and templates. Actions: theme_list, theme_get (theme_id), theme_create (name, styles), theme_update (theme_id + fields), theme_delete (theme_id), template_list, template_get (template_id), template_create (name, subject, body, theme_id), template_update (template_id + fields), template_delete (template_id), template_generate (prompt — AI generates template).';

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
