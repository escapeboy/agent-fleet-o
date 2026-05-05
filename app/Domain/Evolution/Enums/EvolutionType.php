<?php

namespace App\Domain\Evolution\Enums;

enum EvolutionType: string
{
    case AgentConfig = 'agent_config';
    case SkillMutation = 'skill_mutation';
    case CrewRestructure = 'crew_restructure';
}
