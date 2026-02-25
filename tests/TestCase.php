<?php

namespace Tests;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // When base is used as a submodule inside a cloud/parent repo, prefer
        // the parent bootstrap so cloud service providers, migrations, and
        // route overrides are active during tests.
        // Only use the parent bootstrap when the Cloud namespace is registered
        // in the current autoloader (i.e. running inside the cloud repo).
        $parentBootstrap = __DIR__.'/../../bootstrap/app.php';
        $cloudAutoloaded = false;
        foreach (spl_autoload_functions() as $loader) {
            if (is_array($loader) && $loader[0] instanceof ClassLoader) {
                if (array_key_exists('Cloud\\', $loader[0]->getPrefixesPsr4())) {
                    $cloudAutoloaded = true;
                    break;
                }
            }
        }
        $bootstrap = (file_exists($parentBootstrap) && $cloudAutoloaded)
            ? $parentBootstrap
            : __DIR__.'/../bootstrap/app.php';

        $app = require $bootstrap;

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
