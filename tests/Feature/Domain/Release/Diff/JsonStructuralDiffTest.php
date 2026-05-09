<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Release\Diff;

use App\Domain\Release\Services\Diff\JsonStructuralDiff;
use Tests\TestCase;

class JsonStructuralDiffTest extends TestCase
{
    private JsonStructuralDiff $diff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diff = new JsonStructuralDiff;
    }

    public function test_identical_json_produces_no_segments(): void
    {
        $segments = $this->diff->diff('{"a":1,"b":2}', '{"a":1,"b":2}');
        $this->assertSame([], $segments);
    }

    public function test_added_key_produces_add_segment(): void
    {
        $segments = $this->diff->diff('{"a":1}', '{"a":1,"b":2}');

        $this->assertCount(1, $segments);
        $this->assertSame('add', $segments[0]['type']);
        $this->assertSame('$.b', $segments[0]['path']);
        $this->assertSame(2, $segments[0]['right']);
    }

    public function test_removed_key_produces_remove_segment(): void
    {
        $segments = $this->diff->diff('{"a":1,"b":2}', '{"a":1}');

        $this->assertCount(1, $segments);
        $this->assertSame('remove', $segments[0]['type']);
        $this->assertSame('$.b', $segments[0]['path']);
    }

    public function test_changed_value_produces_change_segment(): void
    {
        $segments = $this->diff->diff('{"name":"Alice"}', '{"name":"Bob"}');

        $this->assertCount(1, $segments);
        $this->assertSame('change', $segments[0]['type']);
        $this->assertSame('$.name', $segments[0]['path']);
        $this->assertSame('Alice', $segments[0]['left']);
        $this->assertSame('Bob', $segments[0]['right']);
    }

    public function test_nested_changes_use_jsonpath(): void
    {
        $left = '{"users":[{"name":"alice"}]}';
        $right = '{"users":[{"name":"Alice"}]}';

        $segments = $this->diff->diff($left, $right);

        $this->assertCount(1, $segments);
        $this->assertSame('$.users[0].name', $segments[0]['path']);
    }

    public function test_array_length_changes_emit_per_index(): void
    {
        $segments = $this->diff->diff('{"x":[1,2]}', '{"x":[1,2,3]}');

        $this->assertCount(1, $segments);
        $this->assertSame('add', $segments[0]['type']);
        $this->assertSame('$.x[2]', $segments[0]['path']);
    }

    public function test_invalid_json_returns_unsupported(): void
    {
        $segments = $this->diff->diff('{not json', '{"a":1}');

        $this->assertCount(1, $segments);
        $this->assertSame('unsupported', $segments[0]['type']);
    }

    public function test_supports_application_json(): void
    {
        $this->assertTrue($this->diff->supports('application/json'));
        $this->assertTrue($this->diff->supports('application/ld+json'));
        $this->assertFalse($this->diff->supports('text/plain'));
        $this->assertFalse($this->diff->supports('image/png'));
    }

    public function test_type_change_assoc_to_list_emits_change(): void
    {
        $segments = $this->diff->diff('{"x":{"a":1}}', '{"x":[1]}');

        $this->assertNotEmpty($segments);
        $this->assertSame('change', $segments[0]['type']);
    }
}
