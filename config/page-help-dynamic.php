<?php

declare(strict_types=1);

use App\Domain\Shared\Services\PageHelp\AgentDetailHelpResolver;
use App\Domain\Shared\Services\PageHelp\ExperimentDetailHelpResolver;
use App\Domain\Shared\Services\PageHelp\ProjectDetailHelpResolver;

/*
|--------------------------------------------------------------------------
| Dynamic Page-Help Resolvers
|--------------------------------------------------------------------------
|
| Maps a route name to a resolver class that returns help-array overrides
| computed from the bound route parameters (current entity state).
|
| The resolver class must be invokable: __invoke(array $routeParameters): ?array
|
| Returned array is shallowly merged ON TOP of the static page-help config —
| keys present in the override fully replace the static value; keys absent
| fall through. Returning null (or an empty array) keeps the static help.
|
| Failures inside resolvers must NEVER break the page; PageHelpResolver
| catches and logs.
|
*/

return [
    'agents.show' => AgentDetailHelpResolver::class,
    'experiments.show' => ExperimentDetailHelpResolver::class,
    'projects.show' => ProjectDetailHelpResolver::class,
];
