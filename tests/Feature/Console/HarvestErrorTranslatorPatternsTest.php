<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Shared\Services\ErrorTranslator;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HarvestErrorTranslatorPatternsTest extends TestCase
{
    /** @var array<string, array<string, int>> in-memory hash store */
    private array $hashes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->hashes = [];
        $this->stubRedis();
    }

    private function stubRedis(): void
    {
        $hashes = &$this->hashes;
        Redis::shouldReceive('connection')->andReturnUsing(function () use (&$hashes) {
            return new class($hashes)
            {
                /** @param  array<string, array<string, int>>  $hashes */
                public function __construct(public array &$hashes) {}

                public function hgetall(string $key): array
                {
                    return $this->hashes[$key] ?? [];
                }

                public function hincrby(string $key, string $field, int $by): int
                {
                    $this->hashes[$key][$field] = ($this->hashes[$key][$field] ?? 0) + $by;

                    return $this->hashes[$key][$field];
                }

                public function expire(string $key, int $ttl): bool
                {
                    return true;
                }

                /** @return array{0: int, 1: array<int, string>} */
                public function scan(int $cursor, array $options = []): array
                {
                    $matchPattern = (string) ($options['MATCH'] ?? '*');
                    $regex = '/^'.str_replace('\\*', '.*', preg_quote($matchPattern, '/')).'$/';
                    $keys = array_filter(
                        array_keys($this->hashes),
                        fn ($k) => preg_match($regex, $k) === 1,
                    );

                    return [0, array_values($keys)];
                }
            };
        });
    }

    private function seedHash(string $teamId, string $field, int $hits): void
    {
        $key = 'error_translator:unmatched:'.$teamId;
        $this->hashes[$key][$field] = ($this->hashes[$key][$field] ?? 0) + $hits;
    }

    public function test_returns_empty_global_section_with_no_data(): void
    {
        $this->artisan('error-translator:harvest', ['--format' => 'json'])
            ->expectsOutputToContain('"global": []')
            ->assertExitCode(0);
    }

    public function test_aggregates_global_top_across_teams(): void
    {
        $this->seedHash('team-a', 'fingerprint-1', 5);
        $this->seedHash('team-b', 'fingerprint-1', 3);
        $this->seedHash('team-a', 'fingerprint-2', 1);

        // Capture output via Artisan facade — expectsOutputToContain in Laravel
        // testing chains multiple expectations against substrings of the FULL
        // captured output, but pretty-printed JSON sometimes triggers spurious
        // misses on chained calls. Decode the captured output and assert
        // structurally.
        $exit = \Illuminate\Support\Facades\Artisan::call('error-translator:harvest', [
            '--format' => 'json',
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $exit);
        $report = json_decode($output, true);
        $this->assertIsArray($report, 'Expected JSON output, got: '.substr((string) $output, 0, 200));

        $fp1 = collect($report['global'])->firstWhere('fingerprint', 'fingerprint-1');
        $this->assertNotNull($fp1, 'fingerprint-1 missing from global aggregate');
        $this->assertSame(8, $fp1['hits']);
        $this->assertSame(2, $fp1['teams']);
    }

    public function test_team_filter_limits_scope(): void
    {
        $this->seedHash('team-a', 'fp-a', 10);
        $this->seedHash('team-b', 'fp-b', 99);

        $this->artisan('error-translator:harvest', ['--team' => 'team-a', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('fp-a')
            ->doesntExpectOutputToContain('fp-b');
    }

    public function test_top_option_caps_global_results(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->seedHash('team-a', 'fp-'.$i, $i);
        }

        // top=5 + JSON. The JSON pretty-print will have one "hits": value per
        // global entry plus per-team entries, so we just assert the output
        // mentions fp-30 (highest) and not fp-1.
        $this->artisan('error-translator:harvest', ['--format' => 'json', '--top' => 5])
            ->assertExitCode(0)
            ->expectsOutputToContain('"fingerprint": "fp-30"')
            ->doesntExpectOutputToContain('"fingerprint": "fp-1"');
    }

    public function test_unknown_format_returns_error_exit_code(): void
    {
        $this->artisan('error-translator:harvest', ['--format' => 'xml'])
            ->assertExitCode(\Symfony\Component\Console\Command\Command::INVALID);
    }

    public function test_csv_format_emits_header_and_rows(): void
    {
        $this->seedHash('team-a', 'fp-csv', 3);

        $this->artisan('error-translator:harvest', ['--format' => 'csv'])
            ->expectsOutputToContain('scope,team_id,fingerprint,hits,teams')
            ->expectsOutputToContain('global,,fp-csv,3,1')
            ->assertExitCode(0);
    }

    public function test_translator_unmatched_writes_redis_via_command_pipeline(): void
    {
        // Integration-style: invoke the translator with placeholders that include
        // team_id, then run the harvest and verify the resulting fingerprint
        // shows up.
        $translator = new ErrorTranslator;
        $translator->translateUncached(
            'NeverMatchesAnythingException: foo',
            'en',
            ['team_id' => 'integration-team-x'],
        );

        $this->artisan('error-translator:harvest', ['--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('integration-team-x');
    }
}
