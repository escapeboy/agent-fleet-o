<?php

namespace Tests;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;

abstract class TestCase extends BaseTestCase
{
    /**
     * Flush the array MAIL_MAILER's accumulated SentMessages between tests.
     *
     * Why:
     *   Without this, every notification / email a test triggers stacks
     *   onto ArrayTransport::$messages and never gets released. CI hit
     *   OOM at 3172/3231 tests (Mail/Message.php frame). Calling flush()
     *   each tearDown drops the accumulator without disturbing tests
     *   that explicitly call Mail::fake() (those swap the manager).
     */
    protected function tearDown(): void
    {
        if ($this->app !== null) {
            try {
                $mailer = Mail::mailer('array');
                $transport = $mailer->getSymfonyTransport();
                if ($transport instanceof ArrayTransport) {
                    $transport->flush();
                }
            } catch (\Throwable) {
                // Mail manager not bound or array driver not registered — no-op.
            }
        }

        parent::tearDown();
    }

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

        // paratest worker isolation: when running under paratest, each worker
        // gets a unique TEST_TOKEN env var (1..N for --processes=N). Map that
        // to a distinct Redis DB so per-test tearDown() flushdb() calls
        // don't wipe the keys another worker just wrote. Without this the
        // CreditsTest deduct assertions fail intermittently because worker
        // A's flushdb clears worker B's just-written usage counter mid-test.
        // No-op when phpunit runs serially (TEST_TOKEN unset).
        $token = getenv('TEST_TOKEN');
        if ($token !== false && $token !== '' && (int) $token > 0) {
            $cacheDb = (int) $token;
            putenv('REDIS_CACHE_DB='.$cacheDb);
            $_ENV['REDIS_CACHE_DB'] = $cacheDb;
            $_SERVER['REDIS_CACHE_DB'] = $cacheDb;
        }

        $app = require $bootstrap;

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
