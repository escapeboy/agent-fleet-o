<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ComputeServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    AiServiceProvider::class,
    ComputeServiceProvider::class,
];
