<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Observability\Prometheus\PrometheusRegistry;
use Illuminate\Http\Response;
use Prometheus\RenderTextFormat;

/**
 * Renders the Prometheus text exposition format from the configured collector
 * registry. Mounted at `GET /metrics` behind the InternalNetworkOnly middleware
 * (see routes/web.php). Prometheus scrapes this endpoint every 15s by default.
 *
 * Content-Type: `text/plain; version=0.0.4` per the
 * [Prometheus exposition format](https://prometheus.io/docs/instrumenting/exposition_formats/).
 */
final class PrometheusMetricsController extends Controller
{
    public function __invoke(PrometheusRegistry $registry): Response
    {
        $renderer = new RenderTextFormat;
        $output = $renderer->render($registry->registry()->getMetricFamilySamples());

        return new Response(
            content: $output,
            status: 200,
            headers: [
                'Content-Type' => RenderTextFormat::MIME_TYPE,
                'Cache-Control' => 'no-cache, no-store',
            ],
        );
    }
}
