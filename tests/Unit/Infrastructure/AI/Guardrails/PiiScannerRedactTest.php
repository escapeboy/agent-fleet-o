<?php

namespace Tests\Unit\Infrastructure\AI\Guardrails;

use App\Infrastructure\AI\Guardrails\Scanners\PiiScanner;
use PHPUnit\Framework\TestCase;

class PiiScannerRedactTest extends TestCase
{
    public function test_replaces_email_iban_and_card_with_placeholders(): void
    {
        $result = (new PiiScanner)->redactAll([
            'Contact jane.doe@example.com about IBAN DE89370400440532013000.',
            'Card 4111 1111 1111 1111 and 5500-0000-0000-0004 on file.',
        ]);

        $this->assertSame('Contact [EMAIL_1] about IBAN [IBAN_1].', $result['texts'][0]);
        $this->assertSame('Card [CARD_1] and [CARD_2] on file.', $result['texts'][1]);
        $this->assertSame(['email' => 1, 'iban' => 1, 'card' => 2], $result['counts']);
    }

    public function test_same_value_shares_a_placeholder_across_texts(): void
    {
        $result = (new PiiScanner)->redactAll([
            'System: escalate to ops@example.com or billing@example.org.',
            'User: please mail ops@example.com now.',
        ]);

        $this->assertSame('System: escalate to [EMAIL_1] or [EMAIL_2].', $result['texts'][0]);
        $this->assertSame('User: please mail [EMAIL_1] now.', $result['texts'][1]);
        $this->assertSame(['email' => 2, 'iban' => 0, 'card' => 0], $result['counts']);
    }

    public function test_leaves_non_luhn_numbers_and_plain_text_alone(): void
    {
        $texts = ['Order 4111111111111112 shipped.', 'Nothing to mask here.'];

        $result = (new PiiScanner)->redactAll($texts);

        $this->assertSame($texts, $result['texts']);
        $this->assertSame(['email' => 0, 'iban' => 0, 'card' => 0], $result['counts']);
    }
}
