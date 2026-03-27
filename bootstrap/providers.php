<?php

use App\Infrastructure\RAGFlow\RAGFlowServiceProvider;
use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ComputeServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\IntegrationServiceProvider;
use Barsy\Providers\AgentServiceProvider;
use Barsy\Providers\BarsyServiceProvider;
use Barsy\Providers\ChatServiceProvider;
use Barsy\Providers\LearningServiceProvider;
use Barsy\Providers\LLMServiceProvider;
use SocialiteProviders\Manager\ServiceProvider;

return [
    AppServiceProvider::class,
    RAGFlowServiceProvider::class,
    ServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    AiServiceProvider::class,
    ComputeServiceProvider::class,
    IntegrationServiceProvider::class,
    BarsyServiceProvider::class,
    LLMServiceProvider::class,
    ChatServiceProvider::class,
    LearningServiceProvider::class,
    AgentServiceProvider::class,
];
