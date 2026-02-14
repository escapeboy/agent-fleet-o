<?php

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Actions\ReverseStringAction;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReverseStringTest extends TestCase
{
    private ReverseStringAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new ReverseStringAction;
    }

    #[DataProvider('reverseProvider')]
    public function test_reverses_string(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->action->execute($input));
    }

    public static function reverseProvider(): array
    {
        return [
            'empty string' => ['', ''],
            'single character' => ['a', 'a'],
            'simple word' => ['hello', 'olleh'],
            'palindrome' => ['racecar', 'racecar'],
            'with spaces' => ['hello world', 'dlrow olleh'],
            'with numbers' => ['abc123', '321cba'],
            'unicode cyrillic' => ['привет', 'тевирп'],
            'unicode emoji' => ['🚀🌍', '🌍🚀'],
            'mixed ascii+utf8' => ['café', 'éfac'],
        ];
    }

    public function test_rejects_invalid_utf8(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid UTF-8');

        $this->action->execute("\xFF\xFE");
    }

    public function test_preserves_whitespace(): void
    {
        $this->assertSame('  ba', $this->action->execute('ab  '));
    }

    public function test_reversing_twice_returns_original(): void
    {
        $original = 'Agent Fleet 🚀';
        $reversed = $this->action->execute($original);

        $this->assertSame($original, $this->action->execute($reversed));
    }
}
