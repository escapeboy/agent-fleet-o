<?php

namespace Tests\Unit\Support;

use App\Domain\Agent\Enums\ExecutionTier;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Support\LlmDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The platform default model comes from one place: llm_pricing.default_model when
 * an operator sets it, otherwise the first entry of the provider's catalog. Before
 * this, the key did not exist and every caller fell back to a hard-coded
 * claude-sonnet-4-5, which Anthropic now answers with 404 not_found_error.
 */
class PlatformDefaultModelTest extends TestCase
{
    use RefreshDatabase;

    /** Sonnet 4.5 ids that return 404 for current Anthropic accounts. */
    private const RETIRED_SONNET = ['claude-sonnet-4-5', 'claude-sonnet-4-5-20250929', 'claude-sonnet-4-5-20250514'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['llm_pricing.default_provider' => null, 'llm_pricing.default_model' => null]);
    }

    private function catalogDefault(): string
    {
        return (string) array_key_first(config('llm_providers.anthropic.models'));
    }

    public function test_platform_model_is_the_first_anthropic_catalog_entry(): void
    {
        $this->assertSame('anthropic', LlmDefaults::platformProvider());
        $this->assertSame($this->catalogDefault(), LlmDefaults::platformModel());
        $this->assertNotContains(LlmDefaults::platformModel(), self::RETIRED_SONNET);
    }

    public function test_configured_platform_model_wins_over_the_catalog(): void
    {
        config(['llm_pricing.default_model' => 'claude-opus-5-5']);

        $this->assertSame('claude-opus-5-5', LlmDefaults::platformModel());
        // The single configured model belongs to the default provider only.
        $this->assertSame(array_key_first(config('llm_providers.openai.models')), LlmDefaults::platformModel('openai'));
    }

    public function test_resolver_config_fallback_returns_the_catalog_model(): void
    {
        $resolver = new ProviderResolver;

        $withSource = $resolver->resolveWithSource();
        $this->assertSame('config', $withSource['source']);
        $this->assertSame($this->catalogDefault(), $withSource['model']);

        $this->assertSame(['provider' => 'anthropic', 'model' => $this->catalogDefault()], $resolver->resolve());
        $this->assertSame(['provider' => 'anthropic', 'model' => $this->catalogDefault()], $resolver->resolveInternal(null, 'standard'));
    }

    public function test_config_file_defaults_track_the_catalog(): void
    {
        $default = $this->catalogDefault();

        $this->assertSame($default, config('experiments.model_tiers.expensive.anthropic'));
        $this->assertSame("anthropic/{$default}", config('evaluation.default_judge_model'));
        $this->assertSame("anthropic/{$default}", config('simulation.default_model'));

        foreach (config('agent-templates') as $template) {
            $this->assertNotContains($template['model'], self::RETIRED_SONNET, "Template {$template['slug']} defaults to a retired model");
        }
    }

    public function test_execution_tiers_fall_back_to_the_platform_model(): void
    {
        // null = platform default; ExecuteAgentAction resolves it via LlmDefaults.
        $this->assertNull(ExecutionTier::Standard->config()['model_preference']);
        $this->assertNull(ExecutionTier::Pro->config()['model_preference']);
        $this->assertSame($this->catalogDefault(), ExecutionTier::Standard->config()['model_preference'] ?? LlmDefaults::platformModel());
    }

    public function test_app_code_has_no_retired_sonnet_default_values(): void
    {
        // Array keys (label maps, fallback-chain keys for bridge agents) and help-text
        // examples are allowed; a quoted retired id used as a VALUE is a
        // default/fallback that will 404.
        $pattern = "/['\"](?:anthropic\/)?claude-sonnet-4-5(?:-\d{8})?['\"](?!\s*=>)/";
        $offenders = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) as $i => $line) {
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }
                if (preg_match($pattern, $line)
                    && ! str_contains($line, '->description(')
                    && ! str_contains($line, 'e.g.')
                    && ! str_contains($line, "'example' =>")) {
                    $offenders[] = str_replace(app_path().'/', '', $file->getPathname()).':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Retired claude-sonnet-4-5 used as a default value: '.implode(', ', $offenders));
    }
}
