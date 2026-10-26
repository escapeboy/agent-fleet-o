<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards phpunit.xml: provider keys from a developer's .env or shell must never
 * reach the test run, or tests make live, billed LLM calls.
 */
class TestEnvironmentProviderKeysTest extends TestCase
{
    public function test_platform_provider_keys_are_blank(): void
    {
        foreach (config('services.platform_api_keys', []) as $provider => $key) {
            $this->assertEmpty($key, "services.platform_api_keys.{$provider} is set in the test environment");
        }

        foreach (config('services.sub_program_api_keys', []) as $provider => $key) {
            $this->assertEmpty($key, "services.sub_program_api_keys.{$provider} is set in the test environment");
        }
    }

    public function test_prism_provider_keys_are_blank(): void
    {
        foreach (config('prism.providers', []) as $provider => $settings) {
            $this->assertEmpty($settings['api_key'] ?? null, "prism.providers.{$provider}.api_key is set in the test environment");
        }
    }

    public function test_context_compaction_summarizer_key_is_blank(): void
    {
        $this->assertEmpty(config('context_compaction.summarizer_api_key'));
    }

    public function test_decision_driver_keys_are_blank(): void
    {
        foreach (config('decision.drivers', []) as $driver => $settings) {
            $this->assertEmpty($settings['key'] ?? null, "decision.drivers.{$driver}.key is set in the test environment");
        }
    }
}
