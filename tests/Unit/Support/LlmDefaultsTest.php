<?php

namespace Tests\Unit\Support;

use App\Support\LlmDefaults;
use Tests\TestCase;

class LlmDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure a clean slate — wipe both config paths the helper inspects.
        config([
            'llm_defaults.provider' => null,
            'llm_defaults.model' => null,
            'llm_providers.default_provider' => null,
            'llm_providers.default_model' => null,
        ]);
    }

    public function test_provider_prefers_llm_defaults_config(): void
    {
        config([
            'llm_defaults.provider' => 'openai',
            'llm_providers.default_provider' => 'anthropic',
        ]);

        $this->assertSame('openai', LlmDefaults::provider());
    }

    public function test_provider_falls_back_to_llm_providers_default_provider(): void
    {
        config(['llm_providers.default_provider' => 'openai']);

        $this->assertSame('openai', LlmDefaults::provider());
    }

    public function test_provider_ultimate_fallback_is_bridge_agent(): void
    {
        // Community zero-config fallback should never be 'anthropic' —
        // that requires a paid API key and breaks on bare installs.
        $this->assertSame('bridge_agent', LlmDefaults::provider());
    }

    public function test_provider_ignores_empty_string_config(): void
    {
        config([
            'llm_defaults.provider' => '',
            'llm_providers.default_provider' => '',
        ]);

        $this->assertSame('bridge_agent', LlmDefaults::provider());
    }

    public function test_provider_ignores_non_string_config(): void
    {
        config([
            'llm_defaults.provider' => ['not', 'a', 'string'],
            'llm_providers.default_provider' => null,
        ]);

        $this->assertSame('bridge_agent', LlmDefaults::provider());
    }

    public function test_model_prefers_llm_defaults_config(): void
    {
        config([
            'llm_defaults.model' => 'gpt-4o-mini',
            'llm_providers.default_model' => 'claude-haiku-4-5',
        ]);

        $this->assertSame('gpt-4o-mini', LlmDefaults::model());
    }

    public function test_model_falls_back_to_llm_providers_default_model(): void
    {
        config(['llm_providers.default_model' => 'gpt-4o-mini']);

        $this->assertSame('gpt-4o-mini', LlmDefaults::model());
    }

    public function test_model_ultimate_fallback_is_haiku(): void
    {
        $this->assertSame('claude-haiku-4-5-20251001', LlmDefaults::model());
    }
}
