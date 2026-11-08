<?php

declare(strict_types=1);

namespace Tests\Unit\Skill\Enums;

use App\Domain\Skill\Enums\RiskLevel;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RiskLevelFromMarketplaceTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: RiskLevel}>
     */
    public static function knownValues(): array
    {
        return [
            'low' => ['low', RiskLevel::Low],
            'medium' => ['medium', RiskLevel::Medium],
            'high' => ['high', RiskLevel::High],
            'critical' => ['critical', RiskLevel::Critical],
            'trimmed mixed case' => [' High ', RiskLevel::High],
            'upper case' => ['CRITICAL', RiskLevel::Critical],
            'legacy safe' => ['safe', RiskLevel::Low],
            'legacy safe upper' => ['SAFE', RiskLevel::Low],
            'null' => [null, RiskLevel::Low],
            'empty' => ['', RiskLevel::Low],
            'whitespace' => ['   ', RiskLevel::Low],
        ];
    }

    #[DataProvider('knownValues')]
    public function test_maps_known_values(mixed $value, RiskLevel $expected): void
    {
        $this->assertSame($expected, RiskLevel::fromMarketplace($value));
    }

    public function test_unknown_string_falls_back_to_high_and_logs_warning(): void
    {
        Log::spy();

        $this->assertSame(RiskLevel::High, RiskLevel::fromMarketplace('bogus'));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message, $context) => $message === 'Unknown marketplace risk_level, defaulting to high'
                && $context === ['value' => 'bogus']);
    }

    public function test_non_string_values_fall_back_to_high(): void
    {
        $this->assertSame(RiskLevel::High, RiskLevel::fromMarketplace(5));
        $this->assertSame(RiskLevel::High, RiskLevel::fromMarketplace(['low']));
        $this->assertSame(RiskLevel::High, RiskLevel::fromMarketplace(true));
    }
}
