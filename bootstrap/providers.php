<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ComputeServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\IntegrationServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    AiServiceProvider::class,
    ComputeServiceProvider::class,
    IntegrationServiceProvider::class,
    \Barsy\Providers\BarsyServiceProvider::class,
    \Barsy\Providers\LLMServiceProvider::class,
    \Barsy\Providers\ChatServiceProvider::class,
    \Barsy\Providers\LearningServiceProvider::class,
    \Barsy\Providers\AgentServiceProvider::class,
];
