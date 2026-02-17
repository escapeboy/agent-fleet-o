<?php

namespace Tests\Unit\Domain\Memory\Services;

use App\Domain\Memory\Services\DocumentTextExtractor;
use Tests\TestCase;

class DocumentTextExtractorTest extends TestCase
{
    private DocumentTextExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new DocumentTextExtractor;
    }

    public function test_extracts_text_from_plain_text_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($path, 'Hello World');

        $result = $this->extractor->extract($path, 'text/plain');

        $this->assertEquals('Hello World', $result);

        unlink($path);
    }

    public function test_extracts_text_from_markdown_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($path, "# Title\n\nSome content");

        $result = $this->extractor->extract($path, 'text/markdown');

        $this->assertStringContainsString('Title', $result);
        $this->assertStringContainsString('Some content', $result);

        unlink($path);
    }

    public function test_extracts_csv_with_headers(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($path, "name,age\nAlice,30\nBob,25");

        $result = $this->extractor->extract($path, 'text/csv');

        // Headers become keys via array_combine: "name: Alice, age: 30"
        $this->assertStringContainsString('Alice', $result);
        $this->assertStringContainsString('Bob', $result);
        $this->assertStringContainsString('30', $result);

        unlink($path);
    }

    public function test_handles_csv_with_mismatched_columns(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($path, "name,age\nAlice,30\nBob");

        $result = $this->extractor->extract($path, 'text/csv');

        $this->assertStringContainsString('Alice', $result);
        $this->assertStringContainsString('Bob', $result);

        unlink($path);
    }

    public function test_returns_empty_string_for_csv_without_data(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($path, "name,age\n");

        $result = $this->extractor->extract($path, 'text/csv');

        $this->assertEmpty(trim($result));

        unlink($path);
    }
}
