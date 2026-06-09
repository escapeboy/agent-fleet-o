<?php

namespace Tests\Feature\Architecture;

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Str::markdown() defaults to CommonMark with raw HTML passthrough, so a bare
 * call rendering LLM- or user-influenced content through {!! !!} is stored XSS
 * (14 such sites were fixed in the 2026-06-09 remediation). Every call must
 * pass ['html_input' => 'strip', ...] unless the source is repo-controlled
 * and allowlisted below.
 */
class SafeMarkdownRenderingTest extends TestCase
{
    /**
     * Repo-controlled markdown sources (not user/LLM influenced).
     *
     * @var list<string>
     */
    private const ALLOWLIST = [
        'app/Domain/System/Services/ChangelogParser.php', // renders the repo's own CHANGELOG.md
    ];

    public function test_all_markdown_calls_strip_raw_html(): void
    {
        $violations = [];

        $finder = (new Finder)
            ->files()
            ->in([base_path('app'), base_path('resources/views')])
            ->name(['*.php', '*.blade.php']);

        foreach ($finder as $file) {
            $relative = Str::after($file->getRealPath(), base_path().'/');

            if (in_array($relative, self::ALLOWLIST, true)) {
                continue;
            }

            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (str_contains($line, 'Str::markdown(') && ! str_contains($line, 'html_input')) {
                    $violations[] = "{$relative}:".($i + 1);
                }
            }
        }

        $this->assertSame([], $violations,
            "Bare Str::markdown() without ['html_input' => 'strip'] renders raw HTML (stored XSS for LLM/user content):\n"
            .implode("\n", $violations)
            ."\nAdd the options array, or allowlist the file here if its input is repo-controlled.");
    }
}
