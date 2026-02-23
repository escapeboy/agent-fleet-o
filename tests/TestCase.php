<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // When base is used as a submodule inside a cloud/parent repo, prefer
        // the parent bootstrap so cloud service providers, migrations, and
        // route overrides are active during tests.
        $parentBootstrap = __DIR__.'/../../bootstrap/app.php';
        $bootstrap = file_exists($parentBootstrap)
            ? $parentBootstrap
            : __DIR__.'/../bootstrap/app.php';

        $app = require $bootstrap;

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
