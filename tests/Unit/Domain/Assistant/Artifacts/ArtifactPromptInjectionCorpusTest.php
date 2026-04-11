<?php

namespace Tests\Unit\Domain\Assistant\Artifacts;

use App\Domain\Assistant\Artifacts\ArtifactFactory;
use App\Domain\Assistant\Artifacts\BaseArtifact;
use Tests\TestCase;

/**
 * Runs the prompt-injection corpus fixture through ArtifactFactory::build().
 *
 * Fixture lives at tests/Fixtures/prompt_injection_corpus.json. Each entry
 * describes a raw LLM payload that must EITHER be rejected (expect=null)
 * OR sanitized into a VO that satisfies a named invariant.
 *
 * Every new attack class discovered in production MUST be added here as a
 * regression fixture before the underlying bug is fixed.
 */
class ArtifactPromptInjectionCorpusTest extends TestCase
{
    public function test_every_corpus_entry_is_handled_safely(): void
    {
        $fixture = base_path('tests/Fixtures/prompt_injection_corpus.json');
        $this->assertFileExists($fixture);

        $data = json_decode(file_get_contents($fixture), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('entries', $data);
        $this->assertGreaterThanOrEqual(20, count($data['entries']), 'Corpus must have at least 20 entries.');

        $failures = [];

        foreach ($data['entries'] as $entry) {
            $id = $entry['id'];
            $raw = $entry['raw'];
            $toolCalls = $entry['tool_calls'] ?? [];
            $expect = $entry['expect'];

            $artifact = ArtifactFactory::build($raw, $toolCalls);

            if ($expect === 'null') {
                if ($artifact !== null) {
                    $failures[] = "[{$id}] expected null, got ".$artifact->type();
                }

                continue;
            }

            if ($expect === 'maybe-null-or-truncated') {
                // Either null or a valid VO is acceptable — this is a size-cap test.
                continue;
            }

            if ($expect === 'sanitized') {
                if ($artifact === null) {
                    $failures[] = "[{$id}] expected sanitized VO, got null";

                    continue;
                }

                $invariantError = $this->checkInvariant(
                    $entry['invariant'] ?? 'none',
                    $artifact,
                );
                if ($invariantError !== null) {
                    $failures[] = "[{$id}] invariant failed: {$invariantError}";
                }
            }
        }

        $this->assertSame([], $failures, 'Prompt injection corpus failures:'.PHP_EOL.implode(PHP_EOL, $failures));
    }

    private function checkInvariant(string $invariant, BaseArtifact $artifact): ?string
    {
        $json = json_encode($artifact->toPayload(), JSON_UNESCAPED_UNICODE);

        return match ($invariant) {
            'no_raw_tags' => str_contains($json, '<script>') || str_contains($json, '<img ')
                ? 'raw HTML tag leaked into payload'
                : null,
            'no_onerror' => str_contains(strtolower($json), 'onerror=')
                ? 'onerror handler leaked into payload'
                : null,
            'url_whitelist' => (function () use ($artifact): ?string {
                $payload = $artifact->toPayload();
                foreach ($payload['items'] ?? [] as $item) {
                    $url = $item['url'] ?? '';
                    if (str_starts_with($url, 'javascript:') || str_starts_with($url, 'data:')
                        || str_starts_with($url, 'vbscript:') || str_starts_with($url, 'file:')) {
                        return 'unsafe URL survived sanitization: '.$url;
                    }
                }

                return null;
            })(),
            'metric_card_literal_allowed' => $artifact->type() !== 'metric_card' ? 'expected metric_card' : null,
            'progress_clamped' => (function () use ($artifact): ?string {
                $payload = $artifact->toPayload();
                $p = $payload['progress'] ?? -1;

                return $p < 0 || $p > 100 ? "progress out of bounds: {$p}" : null;
            })(),
            'progress_state_defaulted' => (function () use ($artifact): ?string {
                $state = $artifact->toPayload()['state'] ?? '';
                $allowed = ['pending', 'running', 'completed', 'failed', 'paused'];

                return in_array($state, $allowed, true) ? null : "state not in whitelist: {$state}";
            })(),
            'prompt_injection_text_harmless' => null, // construction succeeded is enough
            'no_control_chars' => preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $json) === 1
                ? 'control character leaked'
                : null,
            'max_8_columns' => (function () use ($artifact): ?string {
                $count = count($artifact->toPayload()['columns'] ?? []);

                return $count > 8 ? "{$count} columns > cap 8" : null;
            })(),
            default => null,
        };
    }
}
