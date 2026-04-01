<?php

namespace App\Http\Controllers;

use App\Domain\Experiment\Services\ArtifactContentResolver;
use App\Infrastructure\A2ui\A2uiRenderer;
use App\Models\Artifact;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ArtifactPreviewController extends Controller
{
    public function render(Artifact $artifact, ?int $version = null): Response
    {
        $artifactVersion = $version
            ? $artifact->versions()->where('version', $version)->firstOrFail()
            : $artifact->versions()->orderByDesc('version')->firstOrFail();

        $content = is_string($artifactVersion->content)
            ? $artifactVersion->content
            : json_encode($artifactVersion->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $category = ArtifactContentResolver::category($artifact->type, $content);

        // Check if JSON content is an A2UI surface
        if ($category === 'json') {
            $decoded = json_decode($content, true);
            if (is_array($decoded) && $this->isA2uiSurface($decoded)) {
                return $this->renderA2ui($decoded, $artifact->name);
            }
        }

        return match ($category) {
            'html' => $this->renderHtml($content),
            'markdown' => $this->renderMarkdown($content, $artifact->name),
            'json' => $this->renderJson($content, $artifact->name),
            default => $this->renderText($content, $artifact->name),
        };
    }

    private function renderHtml(string $content): Response
    {
        return response($content, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Content-Security-Policy', "script-src 'none'; object-src 'none'")
            ->header('Cache-Control', 'private, max-age=3600');
    }

    private function renderMarkdown(string $content, string $title): Response
    {
        $html = Str::markdown($content);
        $wrapped = $this->wrapInShell($html, $title);

        return response($wrapped, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Cache-Control', 'private, max-age=3600');
    }

    private function renderJson(string $content, string $title): Response
    {
        // Pretty-print if not already formatted
        $decoded = json_decode($content);
        if ($decoded !== null) {
            $content = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $escaped = e($content);
        $html = "<pre style=\"margin:0;padding:1.5rem;background:#f8f9fa;font-size:13px;line-height:1.5;overflow:auto;white-space:pre-wrap;word-break:break-word;\"><code>{$escaped}</code></pre>";

        return response($this->wrapInShell($html, $title), 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Cache-Control', 'private, max-age=3600');
    }

    private function renderText(string $content, string $title): Response
    {
        $escaped = e($content);
        $html = "<pre style=\"margin:0;padding:1.5rem;font-size:13px;line-height:1.5;overflow:auto;white-space:pre-wrap;word-break:break-word;\"><code>{$escaped}</code></pre>";

        return response($this->wrapInShell($html, $title), 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Cache-Control', 'private, max-age=3600');
    }

    private function isA2uiSurface(array $data): bool
    {
        // Direct component array: [{id, component}, ...]
        if (isset($data[0]['id'], $data[0]['component'])) {
            return true;
        }
        // Wrapped: {components: [...]}
        if (isset($data['components'][0]['id'])) {
            return true;
        }

        return false;
    }

    private function renderA2ui(array $data, string $title): Response
    {
        $components = $data['components'] ?? $data;
        $dataModel = $data['dataModel'] ?? $data['data_model'] ?? [];

        $renderer = app(A2uiRenderer::class);
        $html = $renderer->render($components, $dataModel)->toHtml();

        // A2UI surfaces are declarative — no scripts needed in preview
        $body = <<<HTML
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tailwindcss/cdn@4" crossorigin="anonymous">
        <div class="max-w-3xl mx-auto py-6">{$html}</div>
        HTML;

        return response($this->wrapInShell($body, $title), 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Content-Security-Policy', "script-src 'none'; object-src 'none'")
            ->header('Cache-Control', 'private, max-age=3600');
    }

    private function wrapInShell(string $body, string $title): string
    {
        $escapedTitle = e($title);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$escapedTitle}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.7;
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
            color: #1a1a2e;
        }
        h1 { font-size: 1.8em; margin-top: 1.5em; margin-bottom: 0.5em; color: #111827; }
        h2 { font-size: 1.4em; margin-top: 1.5em; margin-bottom: 0.5em; color: #1f2937; }
        h3 { font-size: 1.15em; margin-top: 1.2em; margin-bottom: 0.4em; color: #374151; }
        p { margin: 0.8em 0; }
        ul, ol { margin: 0.8em 0; padding-left: 1.5em; }
        li { margin: 0.3em 0; }
        code {
            background: #f3f4f6;
            padding: 0.2em 0.4em;
            border-radius: 4px;
            font-size: 0.9em;
            font-family: 'SF Mono', 'Cascadia Code', 'Fira Code', monospace;
        }
        pre {
            background: #f3f4f6;
            padding: 1em;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 0.85em;
        }
        pre code { background: none; padding: 0; }
        table {
            border-collapse: collapse;
            width: 100%;
            margin: 1em 0;
            font-size: 0.9em;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 0.6em 0.8em;
            text-align: left;
        }
        th { background: #f9fafb; font-weight: 600; }
        blockquote {
            border-left: 3px solid #d1d5db;
            margin: 1em 0;
            padding: 0.5em 1em;
            color: #4b5563;
        }
        a { color: #2563eb; text-decoration: underline; }
        img { max-width: 100%; border-radius: 6px; }
        hr { border: none; border-top: 1px solid #e5e7eb; margin: 2em 0; }
        strong { font-weight: 600; }
    </style>
</head>
<body>{$body}</body>
</html>
HTML;
    }
}
