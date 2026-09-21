<?php

namespace Tests\Feature\Domain\Decision;

use App\Domain\Decision\Drivers\SystemOneDriver;
use App\Domain\Decision\DTOs\ChoiceAnswer;
use App\Domain\Decision\DTOs\NoulAnswer;
use App\Domain\Decision\DTOs\ScoreAnswer;
use App\Domain\Decision\Exceptions\DecisionRequestException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SystemOneDriverTest extends TestCase
{
    private const KEY = 'sk-test-DO-NOT-LEAK-6f2a1b';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function driver(int $retries = 3): SystemOneDriver
    {
        return new SystemOneDriver(
            http: app(HttpFactory::class),
            baseUrl: 'https://api.typesafe.ai',
            apiKey: self::KEY,
            model: 'jev-1.13.0',
            timeout: 5.0,
            retries: $retries,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function questions(): array
    {
        return [
            'department' => [
                'type' => 'choice',
                'instructions' => 'Which team should handle this?',
                'criteria' => ['billing' => 'Payments', 'technical' => 'Bugs', 'other' => 'Anything else'],
            ],
            'frustration' => [
                'type' => 'score',
                'instructions' => 'How frustrated is the customer?',
                'criteria' => ['Calm', 'Frustrated', 'Very angry'],
            ],
            'is_urgent' => [
                'type' => 'noul',
                'instructions' => 'The message conveys urgency',
            ],
        ];
    }

    #[Test]
    public function it_sends_the_documented_request_shape(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->successBody())]);

        $this->driver()->decide('Payouts have been failing for 3 days.', $this->questions());

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.typesafe.ai/v1/systemone'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer '.self::KEY)
                && $body['model'] === 'jev-1.13.0'
                && $body['state'] === 'Payouts have been failing for 3 days.'
                && array_keys($body['questions']) === ['department', 'frustration', 'is_urgent'];
        });
    }

    #[Test]
    public function it_maps_every_answer_type_and_the_usage_block(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->successBody())]);

        $result = $this->driver()->decide('state', $this->questions());

        $this->assertSame('jev-1.13.0', $result->model);
        $this->assertSame(318, $result->inputTokens);
        $this->assertGreaterThanOrEqual(0, $result->latencyMs);

        $choice = $result->answer('department');
        $this->assertInstanceOf(ChoiceAnswer::class, $choice);
        $this->assertSame('billing', $choice->choice);
        $this->assertSame(['billing' => 0.88, 'technical' => 0.12, 'other' => 0.0], $choice->probabilities);
        $this->assertSame(0.81, $choice->confidence);

        $score = $result->answer('frustration');
        $this->assertInstanceOf(ScoreAnswer::class, $score);
        $this->assertSame(1.05, $score->score);
        $this->assertSame(['0' => 'Calm', '1' => 'Frustrated', '2' => 'Very angry'], $score->legend);
        $this->assertSame(0.92, $score->confidence);

        $noul = $result->answer('is_urgent');
        $this->assertInstanceOf(NoulAnswer::class, $noul);
        $this->assertSame(0.95, $noul->noul);
        $this->assertNull($noul->confidence());
        $this->assertNull($noul->probabilities());
    }

    #[Test]
    public function it_retries_a_429_three_times_and_honours_retry_after(): void
    {
        Http::fake([
            'api.typesafe.ai/*' => Http::sequence()
                ->push(['error' => 'slow down'], 429, ['Retry-After' => '2'])
                ->push(['error' => 'slow down'], 429, ['Retry-After' => '1'])
                ->push($this->successBody(), 200),
        ]);

        $result = $this->driver()->decide('state', $this->questions());

        $this->assertSame('billing', $result->answer('department')?->value());
        Http::assertSentCount(3);
        Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 2000, 1);
        Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 1000, 1);
    }

    #[Test]
    public function it_gives_up_after_the_configured_number_of_retries(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(['error' => 'slow down'], 429, ['Retry-After' => '0'])]);

        $this->expectException(DecisionRequestException::class);

        try {
            $this->driver(retries: 3)->decide('state', $this->questions());
        } finally {
            // 1 initial attempt + 3 retries.
            Http::assertSentCount(4);
        }
    }

    #[Test]
    public function it_does_not_retry_a_422(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(['detail' => 'criteria is required'], 422)]);

        try {
            $this->driver()->decide('state', $this->questions());
            $this->fail('Expected a DecisionRequestException.');
        } catch (DecisionRequestException $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('criteria is required', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_failed_request_never_carries_the_api_key_into_the_exception(): void
    {
        // The upstream error body echoes the header back, which is the nastiest
        // version of this leak: the key arrives from OUTSIDE our own config.
        Http::fake(['api.typesafe.ai/*' => Http::response([
            'error' => 'invalid key',
            'received' => ['authorization' => 'Bearer '.self::KEY],
        ], 401)]);

        try {
            $this->driver()->decide('state', $this->questions());
            $this->fail('Expected a DecisionRequestException.');
        } catch (DecisionRequestException $e) {
            $rendered = $e->getMessage()."\n".$e->getTraceAsString()."\n".(string) $e;

            $this->assertStringNotContainsString(self::KEY, $rendered);
            $this->assertStringContainsString('[REDACTED]', $e->getMessage());
            $this->assertSame(401, $e->status);
        }
    }

    #[Test]
    public function batch_mode_answers_every_case_and_re_pools_only_the_rate_limited_ones(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::sequence()
            ->push($this->successBody(), 200)
            ->push(['error' => 'slow down'], 429, ['Retry-After' => '1'])
            ->push($this->successBody(), 200),
        ]);

        $results = $this->driver()->decideBatch([
            'case-a' => ['state' => 'a', 'questions' => $this->questions()],
            'case-b' => ['state' => 'b', 'questions' => $this->questions()],
        ]);

        $this->assertCount(2, $results);
        $this->assertSame('billing', $results['case-a']->answer('department')?->value());
        $this->assertSame('billing', $results['case-b']->answer('department')?->value());
        Http::assertSentCount(3);
    }

    #[Test]
    public function latency_is_the_http_round_trip_and_not_the_retry_backoff(): void
    {
        // Two 429s with a two-second Retry-After each, then a success. Under
        // Sleep::fake() the backoff costs no wall clock, but this pins the
        // intent: retry waiting must never be counted as endpoint latency.
        Http::fake([
            'api.typesafe.ai/*' => Http::sequence()
                ->push(['error' => 'slow down'], 429, ['Retry-After' => '2'])
                ->push(['error' => 'slow down'], 429, ['Retry-After' => '2'])
                ->push($this->successBody(), 200),
        ]);

        $result = $this->driver()->decide('state', $this->questions());

        Http::assertSentCount(3);
        Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 2000, 2);
        $this->assertLessThan(4000, $result->latencyMs);
    }

    #[Test]
    public function latency_prefers_the_transfer_time_the_http_client_measured(): void
    {
        $response = Http::response($this->successBody());
        Http::fake(['api.typesafe.ai/*' => $response]);

        $result = $this->driver()->decide('state', $this->questions());

        // Http::fake() supplies no transfer stats, so the driver falls back to
        // the wall clock and still returns a usable number.
        $this->assertGreaterThanOrEqual(0, $result->latencyMs);

        $reflected = new \ReflectionMethod($this->driver(), 'latencyMs');
        $fake = new \Illuminate\Http\Client\Response(new Response(200, [], '{}'));
        $fake->transferStats = new TransferStats(
            new \GuzzleHttp\Psr7\Request('POST', 'https://api.typesafe.ai/v1/systemone'),
            null,
            0.4321,
        );

        // 0.4321s of transfer time is reported as 432ms, whatever the wall clock said.
        $this->assertSame(432, $reflected->invoke($this->driver(), $fake, hrtime(true) - 9_000_000_000));
    }

    /**
     * @return array<string, mixed>
     */
    private function successBody(): array
    {
        return [
            'model' => 'jev-1.13.0',
            'answers' => [
                'department' => [
                    'type' => 'choice',
                    'choice' => 'billing',
                    'probabilities' => ['billing' => 0.88, 'technical' => 0.12, 'other' => 0.0],
                    'confidence' => 0.81,
                ],
                'frustration' => [
                    'type' => 'score',
                    'score' => 1.05,
                    'legend' => ['0' => 'Calm', '1' => 'Frustrated', '2' => 'Very angry'],
                    'probabilities' => ['0' => 0.0, '1' => 0.95, '2' => 0.05],
                    'confidence' => 0.92,
                ],
                'is_urgent' => ['type' => 'noul', 'noul' => 0.95],
            ],
            'usage' => ['input_tokens' => 318, 'output_tokens' => 34],
        ];
    }
}
