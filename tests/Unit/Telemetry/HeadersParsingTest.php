<?php

namespace Tests\Unit\Telemetry;

use App\Infrastructure\Telemetry\TracerProvider;
use ReflectionClass;
use Tests\TestCase;

class HeadersParsingTest extends TestCase
{
    /**
     * Invoke the private parseHeaders via reflection — it's a pure string
     * parser with no DI, so a direct unit test is simpler than forcing a
     * full TracerProvider build.
     */
    private function parseHeaders(string $raw): array
    {
        $provider = app(TracerProvider::class);
        $r = new ReflectionClass($provider);
        $m = $r->getMethod('parseHeaders');
        $m->setAccessible(true);

        return $m->invoke($provider, $raw);
    }

    public function test_empty_string_returns_empty_array(): void
    {
        $this->assertSame([], $this->parseHeaders(''));
        $this->assertSame([], $this->parseHeaders('   '));
    }

    public function test_single_header_parsed(): void
    {
        $this->assertSame(
            ['Authorization' => 'Bearer xyz'],
            $this->parseHeaders('Authorization=Bearer xyz'),
        );
    }

    public function test_multiple_headers_parsed(): void
    {
        $this->assertSame(
            ['Authorization' => 'Bearer xyz', 'X-Team' => 'fleetq'],
            $this->parseHeaders('Authorization=Bearer xyz,X-Team=fleetq'),
        );
    }

    public function test_surrounding_whitespace_trimmed(): void
    {
        $this->assertSame(
            ['Authorization' => 'Bearer xyz', 'X-Team' => 'fleetq'],
            $this->parseHeaders(' Authorization = Bearer xyz , X-Team = fleetq '),
        );
    }

    public function test_values_may_contain_equal_signs(): void
    {
        // "=" inside a value must not be eaten by the split — only the first.
        $this->assertSame(
            ['Authorization' => 'Basic user=x=='],
            $this->parseHeaders('Authorization=Basic user=x=='),
        );
    }

    public function test_malformed_entries_silently_dropped(): void
    {
        $this->assertSame(
            ['X-Good' => 'ok'],
            $this->parseHeaders('malformed-no-equals,X-Good=ok,=no-key'),
        );
    }

    public function test_value_may_be_empty(): void
    {
        $this->assertSame(
            ['X-Debug' => ''],
            $this->parseHeaders('X-Debug='),
        );
    }
}
