<?php

namespace Tests\Unit\Domain\Migration;

use App\Domain\Migration\Services\CsvParser;
use Tests\TestCase;

class CsvParserTest extends TestCase
{
    private CsvParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CsvParser;
    }

    public function test_empty_payload_returns_empty_result(): void
    {
        $result = $this->parser->parse('');
        $this->assertSame([], $result['headers']);
        $this->assertSame([], $result['rows']);
        $this->assertSame(0, $result['row_count']);
    }

    public function test_parses_simple_comma_csv(): void
    {
        $csv = "name,email\nJane Doe,jane@example.com\nJohn Smith,john@example.com\n";
        $result = $this->parser->parse($csv);

        $this->assertSame(['name', 'email'], $result['headers']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame('Jane Doe', $result['rows'][0]['name']);
        $this->assertSame('john@example.com', $result['rows'][1]['email']);
    }

    public function test_detects_semicolon_delimiter(): void
    {
        $csv = "name;email\nJane;jane@example.com\n";
        $result = $this->parser->parse($csv);

        $this->assertSame(['name', 'email'], $result['headers']);
        $this->assertSame('jane@example.com', $result['rows'][0]['email']);
    }

    public function test_strips_utf8_bom(): void
    {
        $csv = "\xEF\xBB\xBFname,value\nJane,42\n";
        $result = $this->parser->parse($csv);

        $this->assertSame(['name', 'value'], $result['headers']);
    }

    public function test_quoted_values_with_commas_preserved(): void
    {
        $csv = "name,note\nJane,\"Hello, world\"\n";
        $result = $this->parser->parse($csv);

        $this->assertSame('Hello, world', $result['rows'][0]['note']);
    }

    public function test_blank_lines_skipped(): void
    {
        $csv = "name\nJane\n\n\nJohn\n";
        $result = $this->parser->parse($csv);

        $this->assertCount(2, $result['rows']);
        $this->assertSame(2, $result['row_count']);
    }

    public function test_max_rows_cap_respected(): void
    {
        $csv = "name\nA\nB\nC\nD\nE\n";
        $result = $this->parser->parse($csv, maxRows: 2);

        $this->assertCount(2, $result['rows']);
        $this->assertSame(5, $result['row_count'], 'row_count reports the total, not the capped count');
    }

    public function test_duplicate_header_gets_suffix(): void
    {
        $csv = "name,name\nA,B\n";
        $result = $this->parser->parse($csv);

        $this->assertSame(['name', 'name_2'], $result['headers']);
        $this->assertSame('A', $result['rows'][0]['name']);
        $this->assertSame('B', $result['rows'][0]['name_2']);
    }

    public function test_empty_header_column_is_skipped(): void
    {
        $csv = "name,,email\nJane,,jane@example.com\n";
        $result = $this->parser->parse($csv);

        $this->assertSame(['name', 'email'], $result['headers']);
    }

    public function test_missing_values_treated_as_empty_string(): void
    {
        $csv = "name,email,phone\nJane,,+359\n";
        $result = $this->parser->parse($csv);

        $this->assertSame('', $result['rows'][0]['email']);
        $this->assertSame('+359', $result['rows'][0]['phone']);
    }

    public function test_oversized_payload_throws(): void
    {
        $huge = str_repeat('a,b,c,d,e,f,g,h,i,j', 1_000_000); // ~10 MB
        $this->expectException(\RuntimeException::class);
        $this->parser->parse($huge);
    }
}
