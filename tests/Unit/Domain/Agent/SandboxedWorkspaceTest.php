<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Services\SandboxedWorkspace;
use PHPUnit\Framework\TestCase;

class SandboxedWorkspaceTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpBase = sys_get_temp_dir().'/sandbox_test_'.uniqid();
        mkdir($this->tmpBase, 0700, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tmpBase)) {
            $this->deleteDirectory($this->tmpBase);
        }
    }

    private function makeWorkspace(string $execId = 'exec-1', string $agentId = 'agent-1', string $teamId = 'team-1'): SandboxedWorkspace
    {
        return new SandboxedWorkspace($execId, $agentId, $teamId, $this->tmpBase);
    }

    public function test_creates_subdirectories_on_construction(): void
    {
        $ws = $this->makeWorkspace();
        $root = $ws->root();

        $this->assertDirectoryExists($root.'/uploads');
        $this->assertDirectoryExists($root.'/outputs');
        $this->assertDirectoryExists($root.'/tmp');
    }

    public function test_resolve_returns_absolute_path_within_sandbox(): void
    {
        $ws = $this->makeWorkspace();
        $resolved = $ws->resolve('outputs/result.txt');

        $this->assertStringStartsWith($ws->root(), $resolved);
        $this->assertStringContainsString('result.txt', $resolved);
    }

    public function test_resolve_accepts_nested_paths(): void
    {
        $ws = $this->makeWorkspace();
        $resolved = $ws->resolve('tmp/subdir/file.json');

        $this->assertStringStartsWith($ws->root(), $resolved);
        $this->assertStringContainsString('file.json', $resolved);
    }

    public function test_resolve_blocks_path_traversal_with_double_dots(): void
    {
        $ws = $this->makeWorkspace();

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessageMatches('/traversal/i');

        $ws->resolve('../../etc/passwd');
    }

    public function test_resolve_blocks_path_traversal_deeply_nested(): void
    {
        $ws = $this->makeWorkspace();

        $this->expectException(\OutOfBoundsException::class);

        $ws->resolve('tmp/../../../etc/shadow');
    }

    public function test_resolve_accepts_empty_path_as_root(): void
    {
        $ws = $this->makeWorkspace();

        $resolved = $ws->resolve('');
        $this->assertSame($ws->root(), $resolved);
    }

    public function test_teardown_removes_sandbox_directory(): void
    {
        $ws = $this->makeWorkspace('exec-td', 'agent-td', 'team-td');
        $root = $ws->root();

        $this->assertDirectoryExists($root);

        $ws->teardown();

        $this->assertDirectoryDoesNotExist($root);
    }

    public function test_teardown_is_safe_when_already_removed(): void
    {
        $ws = $this->makeWorkspace('exec-safe', 'agent-1', 'team-1');
        $ws->teardown();

        // Must not throw on second call
        $ws->teardown();

        $this->assertTrue(true);
    }

    public function test_teardown_removes_nested_files(): void
    {
        $ws = $this->makeWorkspace('exec-nested', 'agent-1', 'team-1');
        file_put_contents($ws->uploadsDir().'/input.txt', 'hello');
        file_put_contents($ws->outputsDir().'/result.json', '{}');

        $ws->teardown();

        $this->assertDirectoryDoesNotExist($ws->root());
    }

    public function test_subdirectory_accessors_are_within_root(): void
    {
        $ws = $this->makeWorkspace();

        $this->assertStringStartsWith($ws->root(), $ws->uploadsDir());
        $this->assertStringStartsWith($ws->root(), $ws->outputsDir());
        $this->assertStringStartsWith($ws->root(), $ws->tmpDir());
    }

    public function test_execution_id_accessor(): void
    {
        $ws = $this->makeWorkspace('my-exec-id');
        $this->assertSame('my-exec-id', $ws->executionId());
    }

    private function deleteDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }
}
