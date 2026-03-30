<?php

namespace Tests\Unit\Domain\Credential;

use App\Domain\Credential\Services\SecretPatternLibrary;
use PHPUnit\Framework\TestCase;

class SecretPatternLibraryTest extends TestCase
{
    private SecretPatternLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = new SecretPatternLibrary;
    }

    public function test_patterns_returns_at_least_15_entries(): void
    {
        $this->assertGreaterThanOrEqual(15, count($this->library->patterns()));
    }

    public function test_detects_openai_key(): void
    {
        $findings = $this->library->scan('Use sk-abcdefghijklmnopqrstuvwxyz1234567890ABCDEFGH12 for auth');
        $ids = array_column($findings, 'pattern_id');
        $this->assertContains('OPENAI_KEY', $ids);
    }

    public function test_detects_anthropic_key(): void
    {
        $key = 'sk-ant-'.str_repeat('a', 90);
        $findings = $this->library->scan("MY_KEY={$key}");
        $ids = array_column($findings, 'pattern_id');
        $this->assertContains('ANTHROPIC_KEY', $ids);
    }

    public function test_detects_github_pat(): void
    {
        // GitHub PATs are ghp_ followed by exactly 36 alphanumeric characters
        $pat = 'ghp_'.str_repeat('A', 36);
        $findings = $this->library->scan("{$pat} access token");
        $ids = array_column($findings, 'pattern_id');
        $this->assertContains('GITHUB_PAT', $ids);
    }

    public function test_detects_aws_access_key(): void
    {
        $findings = $this->library->scan('AWS_KEY=AKIAIOSFODNN7EXAMPLE');
        $ids = array_column($findings, 'pattern_id');
        $this->assertContains('AWS_ACCESS_KEY', $ids);
    }

    public function test_detects_stripe_secret_key(): void
    {
        // Built at runtime so static secret scanners do not flag test fixtures.
        $fakeKey = 'sk_live_' . str_repeat('a', 24);
        $findings = $this->library->scan($fakeKey);
        $ids = array_column($findings, 'pattern_id');
        $this->assertContains('STRIPE_SECRET', $ids);
    }

    public function test_detects_stripe_test_key(): void
    {
        // Built at runtime so static secret scanners do not flag test fixtures.
        $fakeKey = 'sk_test_' . str_repeat('a', 24);
        $findings = $this->library->scan($fakeKey);
        $ids = array_column($findings, 'pattern_id');
        $this->assertContains('STRIPE_TEST', $ids);
    }

    public function test_detects_google_api_key(): void
    {
        $findings = $this->library->scan('AIzaSyDdI0hCZtE6vySjMm-WEfRq3CPzqKqqsHI');
        $ids = array_column($findings, 'pattern_id');
        $this->assertContains('GOOGLE_API', $ids);
    }

    public function test_detects_pem_private_key(): void
    {
        $findings = $this->library->scan("-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAK...");
        $ids = array_column($findings, 'pattern_id');
        $this->assertContains('GENERIC_PRIVATE_KEY', $ids);
    }

    public function test_returns_empty_for_clean_text(): void
    {
        $findings = $this->library->scan('This is a normal system prompt with no secrets.');
        $this->assertEmpty($findings);
    }

    public function test_scan_fields_returns_field_names_in_findings(): void
    {
        $fakeKey = 'sk_live_' . str_repeat('c', 24);
        $findings = $this->library->scanFields([
            'role' => 'You are a helpful assistant',
            'goal' => "{$fakeKey} is the payment key",
        ]);

        $this->assertCount(1, $findings);
        $this->assertEquals('goal', $findings[0]['field']);
        $this->assertEquals('STRIPE_SECRET', $findings[0]['pattern_id']);
    }

    public function test_scan_fields_skips_non_string_values(): void
    {
        // Should not throw; non-string values are skipped gracefully
        $findings = $this->library->scanFields([
            'config' => '',
        ]);

        $this->assertEmpty($findings);
    }

    public function test_scan_deduplicates_multiple_matches_of_same_pattern(): void
    {
        // Two Stripe test keys built at runtime so static secret scanners do not flag test fixtures.
        $key1 = 'sk_test_' . str_repeat('a', 24);
        $key2 = 'sk_test_' . str_repeat('A', 24);
        $text = "{$key1} and {$key2}";
        $findings = $this->library->scan($text);
        $stripeFindings = array_filter($findings, fn ($f) => $f['pattern_id'] === 'STRIPE_TEST');
        $this->assertCount(1, array_values($stripeFindings));
    }
}
