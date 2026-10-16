<?php

namespace App\Infrastructure\AI\Gateways;

use Generator;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Providers\Anthropic\Anthropic;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Psr\Http\Message\RequestInterface;

/**
 * Prism's Anthropic provider plus `output_config.effort`.
 *
 * Prism v0.100 builds the request body in a static method and has no effort
 * field, and the models that reject budget_tokens (Opus 4.7+, every 5.x) take
 * effort as their only depth control. The gateway passes the level as the
 * `effort` provider option; this adds it to the JSON body of every request the
 * call makes, including each step of a tool loop.
 */
class PrismAnthropicProvider extends Anthropic
{
    private ?string $effort = null;

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $this->effort = $this->effortFrom($request);

        try {
            return parent::text($request);
        } finally {
            $this->effort = null;
        }
    }

    /**
     * @return Generator<StreamEvent>
     */
    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        // parent::stream() builds its client before returning the generator.
        $this->effort = $this->effortFrom($request);

        try {
            return parent::stream($request);
        } finally {
            $this->effort = null;
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    #[\Override]
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        $client = parent::client($options, $retry, $baseUrl);
        $effort = $this->effort;

        if ($effort === null) {
            return $client;
        }

        return $client->withRequestMiddleware(function (RequestInterface $request) use ($effort): RequestInterface {
            // Decode to objects so empty JSON objects (e.g. a tool schema's
            // `properties: {}`) are not re-encoded as arrays.
            $body = json_decode((string) $request->getBody());
            if (! $body instanceof \stdClass) {
                return $request;
            }

            $body->output_config ??= new \stdClass;
            $body->output_config->effort = $effort;

            $stream = Utils::streamFor(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $request->withBody($stream)->withHeader('Content-Length', (string) $stream->getSize());
        });
    }

    private function effortFrom(TextRequest $request): ?string
    {
        $effort = $request->providerOptions('effort');

        return is_string($effort) ? $effort : null;
    }
}
