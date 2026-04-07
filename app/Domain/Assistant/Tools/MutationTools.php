<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Tools\Mutations\AdminMutationTools;
use App\Domain\Assistant\Tools\Mutations\AgentMutationTools;
use App\Domain\Assistant\Tools\Mutations\CrewMutationTools;
use App\Domain\Assistant\Tools\Mutations\DataMutationTools;
use App\Domain\Assistant\Tools\Mutations\ExperimentMutationTools;
use App\Domain\Assistant\Tools\Mutations\ProjectMutationTools;
use App\Domain\Assistant\Tools\Mutations\WorkflowMutationTools;
use Prism\Prism\Tool as PrismToolObject;

class MutationTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function writeTools(): array
    {
        return array_merge(
            ProjectMutationTools::writeTools(),
            AgentMutationTools::writeTools(),
            CrewMutationTools::writeTools(),
            ExperimentMutationTools::writeTools(),
            WorkflowMutationTools::writeTools(),
            AdminMutationTools::writeTools(),
            DataMutationTools::writeTools(),
        );
    }

    /**
     * @return array<PrismToolObject>
     */
    public static function destructiveTools(): array
    {
        return array_merge(
            ExperimentMutationTools::destructiveTools(),
            ProjectMutationTools::destructiveTools(),
            AgentMutationTools::destructiveTools(),
            DataMutationTools::destructiveTools(),
            AdminMutationTools::destructiveTools(),
        );
    }
}
