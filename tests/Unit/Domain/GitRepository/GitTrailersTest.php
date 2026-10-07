<?php

namespace Tests\Unit\Domain\GitRepository;

use App\Domain\GitRepository\Support\GitTrailers;
use PHPUnit\Framework\TestCase;

class GitTrailersTest extends TestCase
{
    public function test_empty_trailers_leave_message_unchanged(): void
    {
        $this->assertSame("fix: a\n\n", GitTrailers::append("fix: a\n\n", []));
    }

    public function test_adds_blank_line_and_trims_trailing_whitespace(): void
    {
        $this->assertSame(
            "fix: a\n\nFleetQ-Agent: ag1",
            GitTrailers::append("fix: a  \n\n", ['FleetQ-Agent' => 'ag1']),
        );
    }

    public function test_does_not_duplicate_existing_key(): void
    {
        $msg = "fix: a\n\nFleetQ-Agent: ag1";
        $this->assertSame(
            $msg."\nFleetQ-Experiment: ex1",
            GitTrailers::append($msg, ['FleetQ-Agent' => 'ag2', 'FleetQ-Experiment' => 'ex1']),
        );
        $this->assertSame($msg, GitTrailers::append($msg, ['FleetQ-Agent' => 'ag2']));
    }

    public function test_multi_line_body_preserved(): void
    {
        $out = GitTrailers::append("feat: x\n\nLine one.\nLine two.\n", ['FleetQ-Experiment' => 'e']);
        $this->assertSame("feat: x\n\nLine one.\nLine two.\n\nFleetQ-Experiment: e", $out);
    }
}
