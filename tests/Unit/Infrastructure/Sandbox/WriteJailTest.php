<?php

namespace Tests\Unit\Infrastructure\Sandbox;

use App\Infrastructure\Sandbox\WriteJail;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class WriteJailTest extends TestCase
{
    private function jail(): WriteJail
    {
        return new WriteJail;
    }

    private array $command = ['claude', '-p', '--model', 'x'];

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir().'/wjail-*') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_disabled_returns_command_verbatim(): void
    {
        config(['experiments.warm_build.write_jail.enabled' => false]);
        $this->assertSame($this->command, $this->jail()->wrap($this->command, ['/wt']));
        $this->assertSame('disabled', $this->jail()->inertReason());
    }

    public function test_enabled_but_launcher_unset_is_inert(): void
    {
        config([
            'experiments.warm_build.write_jail.enabled' => true,
            'experiments.warm_build.write_jail.launcher' => null,
        ]);
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->assertSame('not-linux', $this->jail()->inertReason());

            return;
        }
        $this->assertSame('launcher-unset', $this->jail()->inertReason());
        $this->assertSame($this->command, $this->jail()->wrap($this->command, ['/wt']));
    }

    public function test_enabled_but_launcher_missing_is_inert(): void
    {
        config([
            'experiments.warm_build.write_jail.enabled' => true,
            'experiments.warm_build.write_jail.launcher' => '/does/not/exist/landlock-helper',
        ]);
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('launcher checks only reached on Linux');
        }
        $this->assertSame('launcher-missing', $this->jail()->inertReason());
        $this->assertSame($this->command, $this->jail()->wrap($this->command, ['/wt']));
    }

    public function test_enabled_with_executable_launcher_wraps_command(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Landlock jail only wraps on Linux');
        }

        $launcher = sys_get_temp_dir().'/wjail-'.Str::random(8);
        File::put($launcher, "#!/bin/sh\nexec \"\$@\"\n");
        chmod($launcher, 0755);

        config([
            'experiments.warm_build.write_jail.enabled' => true,
            'experiments.warm_build.write_jail.launcher' => $launcher,
            'experiments.warm_build.write_jail.extra_writable' => ['/tmp'],
        ]);

        $wrapped = $this->jail()->wrap($this->command, ['/wt/run/app', '/home/ephemeral']);

        $this->assertSame($launcher, $wrapped[0]);
        // writable paths + the separator + the original command are all present.
        $this->assertContains('/wt/run/app', $wrapped);
        $this->assertContains('/home/ephemeral', $wrapped);
        $this->assertContains('/tmp', $wrapped);
        $sep = array_search('--', $wrapped, true);
        $this->assertIsInt($sep);
        $this->assertSame($this->command, array_values(array_slice($wrapped, $sep + 1)));
        // every '--writable' is immediately followed by a path, never '--'
        foreach ($wrapped as $k => $tok) {
            if ($tok === '--writable') {
                $this->assertNotSame('--', $wrapped[$k + 1] ?? '--');
            }
        }
    }

    public function test_duplicate_writable_paths_collapsed(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Landlock jail only wraps on Linux');
        }
        $launcher = sys_get_temp_dir().'/wjail-'.Str::random(8);
        File::put($launcher, "#!/bin/sh\nexec \"\$@\"\n");
        chmod($launcher, 0755);
        config([
            'experiments.warm_build.write_jail.enabled' => true,
            'experiments.warm_build.write_jail.launcher' => $launcher,
            'experiments.warm_build.write_jail.extra_writable' => ['/tmp'],
        ]);

        $wrapped = $this->jail()->wrap($this->command, ['/wt', '/wt', '/tmp']);

        $this->assertSame(1, array_count_values($wrapped)['/wt'] ?? 0, '/wt appears once');
    }
}
