<?php

namespace Tests\Unit\Domain\Signal;

use App\Domain\Signal\Support\PayloadSanitizer;
use PHPUnit\Framework\TestCase;

class PayloadSanitizerTest extends TestCase
{
    public function test_removes_nul_from_strings_and_keys_at_any_depth(): void
    {
        $input = [
            "ti\0tle" => "a\0b",
            'nested' => [
                "k\0ey" => ["deep\0er" => "x\0y\0z"],
                'list' => ["one\0", 'two'],
            ],
        ];

        $this->assertSame([
            'title' => 'ab',
            'nested' => [
                'key' => ['deeper' => 'xyz'],
                'list' => ['one', 'two'],
            ],
        ], PayloadSanitizer::clean($input));
    }

    public function test_keeps_key_order(): void
    {
        $this->assertSame(['b', 'a', 'c'], array_keys(PayloadSanitizer::clean(['b' => 1, "a\0" => 2, 'c' => 3])));
    }

    public function test_replaces_invalid_utf8(): void
    {
        $cleaned = PayloadSanitizer::clean(['msg' => "bad\xC3\x28 one", "k\xFF" => 'v']);

        foreach ($cleaned as $key => $value) {
            $this->assertTrue(mb_check_encoding((string) $key, 'UTF-8'));
            $this->assertTrue(mb_check_encoding($value, 'UTF-8'));
        }
        $this->assertNotFalse(json_encode($cleaned));
        $this->assertStringContainsString('one', $cleaned['msg']);
    }

    public function test_leaves_scalars_and_clean_strings_unchanged(): void
    {
        $input = ['i' => 42, 'f' => 1.5, 't' => true, 'f2' => false, 'n' => null, 's' => 'héllo wörld', 7 => 'x'];

        $this->assertSame($input, PayloadSanitizer::clean($input));
        $this->assertSame(42, PayloadSanitizer::clean(42));
        $this->assertSame(1.5, PayloadSanitizer::clean(1.5));
        $this->assertTrue(PayloadSanitizer::clean(true));
        $this->assertNull(PayloadSanitizer::clean(null));
    }

    public function test_clean_string(): void
    {
        $this->assertSame(
            [null, 'ISSUE-1', 'plain'],
            [PayloadSanitizer::cleanString(null), PayloadSanitizer::cleanString("ISS\0UE-1"), PayloadSanitizer::cleanString('plain')],
        );
    }
}
