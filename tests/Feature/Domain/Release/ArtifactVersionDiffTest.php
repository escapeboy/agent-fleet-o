<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Release;

use App\Domain\Release\Services\ArtifactVersionDiff;
use Tests\TestCase;

class ArtifactVersionDiffTest extends TestCase
{
    public function test_identical_strings_produce_only_context_segments(): void
    {
        $diff = (new ArtifactVersionDiff)->diff("a\nb\nc", "a\nb\nc");

        foreach ($diff as $segment) {
            $this->assertSame('context', $segment['type']);
        }
    }

    public function test_addition_produces_add_segment(): void
    {
        $diff = (new ArtifactVersionDiff)->diff("a\nc", "a\nb\nc");
        $types = array_column($diff, 'type');

        $this->assertContains('add', $types);
        $this->assertNotContains('remove', $types);
    }

    public function test_removal_produces_remove_segment(): void
    {
        $diff = (new ArtifactVersionDiff)->diff("a\nb\nc", "a\nc");
        $types = array_column($diff, 'type');

        $this->assertContains('remove', $types);
        $this->assertNotContains('add', $types);
    }

    public function test_modification_produces_both_add_and_remove(): void
    {
        $diff = (new ArtifactVersionDiff)->diff("a\nb\nc", "a\nB\nc");
        $types = array_column($diff, 'type');

        $this->assertContains('add', $types);
        $this->assertContains('remove', $types);
    }

    public function test_binary_content_returns_unsupported_segment(): void
    {
        $diff = (new ArtifactVersionDiff)->diff('text', "bin\x00ary");

        $this->assertCount(1, $diff);
        $this->assertSame('unsupported', $diff[0]['type']);
    }

    public function test_oversized_diff_returns_unsupported(): void
    {
        $left = str_repeat("line\n", 2001);
        $right = str_repeat("changed\n", 2001);

        $diff = (new ArtifactVersionDiff)->diff($left, $right);

        $this->assertCount(1, $diff);
        $this->assertSame('unsupported', $diff[0]['type']);
        $this->assertStringContainsString('too large', $diff[0]['text']);
    }

    public function test_empty_inputs_produce_empty_diff(): void
    {
        $diff = (new ArtifactVersionDiff)->diff('', '');

        $this->assertSame([], $diff);
    }

    public function test_handles_null_inputs_safely(): void
    {
        $diff = (new ArtifactVersionDiff)->diff(null, null);

        $this->assertSame([], $diff);
    }

    public function test_handles_crlf_and_mixed_line_endings(): void
    {
        $diff = (new ArtifactVersionDiff)->diff("a\r\nb", "a\nb");

        // After normalization both should appear identical -> all context.
        foreach ($diff as $segment) {
            $this->assertSame('context', $segment['type']);
        }
    }
}
