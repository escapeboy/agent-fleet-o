<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Models\SandboxFileActivity;
use App\Domain\Agent\Services\SandboxedWorkspace;
use App\Domain\Agent\Services\SandboxFileActivityCollector;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SandboxFileActivityCollectorTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->basePath = sys_get_temp_dir().'/sbtest-'.uniqid();
    }

    private function workspace(string $execId = 'exec-1'): SandboxedWorkspace
    {
        return new SandboxedWorkspace($execId, 'agent-x', $this->team->id, $this->basePath);
    }

    public function test_collects_files_from_outputs_directory(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->outputsDir().'/report.md', 'hello world');
        file_put_contents($ws->outputsDir().'/data.json', '{}');

        $count = (new SandboxFileActivityCollector)->collect($ws, ['team_id' => $this->team->id]);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('sandbox_file_activities', [
            'team_id' => $this->team->id,
            'path' => 'report.md',
            'operation' => 'created',
        ]);

        $ws->teardown();
    }

    public function test_empty_outputs_directory_records_nothing(): void
    {
        $ws = $this->workspace();

        $count = (new SandboxFileActivityCollector)->collect($ws, ['team_id' => $this->team->id]);

        $this->assertSame(0, $count);
        $this->assertSame(0, SandboxFileActivity::count());

        $ws->teardown();
    }

    public function test_missing_outputs_directory_is_a_no_op(): void
    {
        $ws = $this->workspace();
        // Simulate a sandbox without filesystem support (cloud-safe path).
        rmdir($ws->outputsDir());

        $count = (new SandboxFileActivityCollector)->collect($ws, ['team_id' => $this->team->id]);

        $this->assertSame(0, $count);

        $ws->teardown();
    }

    public function test_caps_at_max_files(): void
    {
        $ws = $this->workspace();
        for ($i = 0; $i < SandboxFileActivityCollector::MAX_FILES + 5; $i++) {
            file_put_contents($ws->outputsDir()."/file-{$i}.txt", 'x');
        }

        $count = (new SandboxFileActivityCollector)->collect($ws, ['team_id' => $this->team->id]);

        $this->assertSame(SandboxFileActivityCollector::MAX_FILES, $count);

        $ws->teardown();
    }

    public function test_skips_symlinks_pointing_outside_the_sandbox(): void
    {
        $ws = $this->workspace();
        file_put_contents($ws->outputsDir().'/real.txt', 'ok');

        // A symlink a malicious agent could plant pointing at the host.
        $outside = sys_get_temp_dir().'/sbtest-outside-'.uniqid().'.txt';
        file_put_contents($outside, 'secret');
        symlink($outside, $ws->outputsDir().'/escape.txt');

        $count = (new SandboxFileActivityCollector)->collect($ws, ['team_id' => $this->team->id]);

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('sandbox_file_activities', [
            'team_id' => $this->team->id,
            'path' => 'real.txt',
        ]);
        $this->assertDatabaseMissing('sandbox_file_activities', ['path' => 'escape.txt']);

        @unlink($ws->outputsDir().'/escape.txt');
        $ws->teardown();
        @unlink($outside);
    }
}
