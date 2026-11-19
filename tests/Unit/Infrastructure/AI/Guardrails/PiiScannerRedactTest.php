<?php

namespace Tests\Unit\Infrastructure\AI\Guardrails;

use App\Infrastructure\AI\Guardrails\Scanners\PiiScanner;
use Tests\TestCase;

class PiiScannerRedactTest extends TestCase
{
    public function test_replaces_email_iban_and_card_with_numbered_placeholders(): void
    {
        $result = (new PiiScanner)->redactAll([
            'Mail jane.doe@example.com about IBAN DE89370400440532013000.',
            'Card 4242 4242 4242 4242 today, backup 5555-5555-5555-4444 ok',
        ]);

        $this->assertSame([
            'Mail [EMAIL_1] about IBAN [IBAN_1].',
            'Card [CARD_1] today, backup [CARD_2] ok',
        ], $result['texts']);
        $this->assertSame(['email' => 1, 'iban' => 1, 'card' => 2], $result['counts']);
    }

    public function test_same_value_gets_same_placeholder_across_texts_and_distinct_values_increase(): void
    {
        $result = (new PiiScanner)->redactAll([
            'Contact a@example.com or b@example.org',
            'Reply to b@example.org and a@example.com, then c@example.net',
        ]);

        $this->assertSame('Contact [EMAIL_1] or [EMAIL_2]', $result['texts'][0]);
        $this->assertSame('Reply to [EMAIL_2] and [EMAIL_1], then [EMAIL_3]', $result['texts'][1]);
        $this->assertSame(3, $result['counts']['email']);
    }

    public function test_leaves_non_luhn_numbers_and_clean_text_untouched(): void
    {
        $text = 'Order 1234 5678 9012 3456 shipped. Nothing else here.';

        $result = (new PiiScanner)->redactAll([$text, '']);

        $this->assertSame([$text, ''], $result['texts']);
        $this->assertSame(['email' => 0, 'iban' => 0, 'card' => 0], $result['counts']);
    }
}
