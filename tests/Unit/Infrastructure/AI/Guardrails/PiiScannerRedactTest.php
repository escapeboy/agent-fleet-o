<?php

namespace Tests\Unit\Infrastructure\AI\Guardrails;

use App\Infrastructure\AI\Guardrails\Scanners\PiiScanner;
use Tests\TestCase;

class PiiScannerRedactTest extends TestCase
{
    public function test_replaces_email_iban_and_card_with_placeholders(): void
    {
        $result = (new PiiScanner)->redactAll([
            'Mail jane.doe@example.com, IBAN DE89370400440532013000, card 4242 4242 4242 4242 today.',
        ]);

        $this->assertSame(
            'Mail [EMAIL_1], IBAN [IBAN_1], card [CARD_1] today.',
            $result['texts'][0],
        );
        $this->assertSame(['email' => 1, 'iban' => 1, 'card' => 1], $result['counts']);
    }

    public function test_card_with_dashes_is_replaced(): void
    {
        $result = (new PiiScanner)->redactAll(['pay with 4242-4242-4242-4242 please']);

        $this->assertSame('pay with [CARD_1] please', $result['texts'][0]);
    }

    public function test_luhn_invalid_number_is_left_alone(): void
    {
        $result = (new PiiScanner)->redactAll(['ref 1234 5678 9012 3456 here']);

        $this->assertSame('ref 1234 5678 9012 3456 here', $result['texts'][0]);
        $this->assertSame(['email' => 0, 'iban' => 0, 'card' => 0], $result['counts']);
    }

    public function test_same_value_shares_placeholder_across_texts_and_distinct_values_increment(): void
    {
        $result = (new PiiScanner)->redactAll([
            'System: contact a@example.com or b@example.com',
            'User: write to a@example.com about card 5555555555554444',
        ]);

        $this->assertSame('System: contact [EMAIL_1] or [EMAIL_2]', $result['texts'][0]);
        $this->assertSame('User: write to [EMAIL_1] about card [CARD_1]', $result['texts'][1]);
        $this->assertSame(['email' => 2, 'iban' => 0, 'card' => 1], $result['counts']);
    }

    public function test_text_without_pii_is_unchanged(): void
    {
        $result = (new PiiScanner)->redactAll(['', 'Order 42 shipped on 2026-01-01.']);

        $this->assertSame(['', 'Order 42 shipped on 2026-01-01.'], $result['texts']);
        $this->assertSame(0, array_sum($result['counts']));
    }
}
