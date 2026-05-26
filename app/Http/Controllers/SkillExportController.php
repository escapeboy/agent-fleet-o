<?php

namespace App\Http\Controllers;

use App\Domain\Skill\Actions\ExportSkillToAgentSkillsAction;
use App\Domain\Skill\Models\Skill;
use Symfony\Component\HttpFoundation\Response;

class SkillExportController extends Controller
{
    public function __invoke(Skill $skill, ExportSkillToAgentSkillsAction $export): Response
    {
        $content = $export->execute($skill);
        $filename = ($skill->slug ?: 'skill').'.SKILL.md';

        return response($content, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
