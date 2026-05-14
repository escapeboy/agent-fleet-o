<?php

namespace Tests\Unit\Domain\Signal\Services;

use App\Domain\Signal\Services\CodeOwnershipResolver;
use PHPUnit\Framework\TestCase;

class CodeOwnershipResolverTest extends TestCase
{
    private CodeOwnershipResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new CodeOwnershipResolver;
    }

    public function test_returns_empty_when_payload_has_no_signals(): void
    {
        $this->assertSame([], $this->resolver->resolve([]));
    }

    public function test_returns_explicit_code_owners(): void
    {
        $owners = $this->resolver->resolve([
            'code_owners' => ['@frontend-team', 'alice@example.com'],
        ]);

        $this->assertSame(['@frontend-team', 'alice@example.com'], $owners);
    }

    public function test_matches_codeowners_rules_against_affected_files(): void
    {
        $owners = $this->resolver->resolve([
            'affected_files' => ['app/Domain/Signal/Models/Signal.php'],
            'code_owners_rules' => [
                ['pattern' => 'app/Domain/Signal/**', 'owners' => ['@signal-team']],
                ['pattern' => 'app/Domain/Outbound/**', 'owners' => ['@outbound-team']],
            ],
        ]);

        $this->assertSame(['@signal-team'], $owners);
    }

    public function test_anchored_pattern_only_matches_at_root(): void
    {
        $owners = $this->resolver->resolve([
            'affected_files' => ['vendor/lib/Foo.php'],
            'code_owners_rules' => [
                ['pattern' => '/app/**', 'owners' => ['@app-team']],
            ],
        ]);

        $this->assertSame([], $owners);
    }

    public function test_directory_pattern_matches_descendants(): void
    {
        $owners = $this->resolver->resolve([
            'affected_files' => ['app/Domain/Signal/Models/Signal.php'],
            'code_owners_rules' => [
                ['pattern' => 'app/Domain/Signal/', 'owners' => ['@signal-team']],
            ],
        ]);

        $this->assertSame(['@signal-team'], $owners);
    }

    public function test_extracts_files_from_stack_trace(): void
    {
        $stackTrace = <<<'TRACE'
            #0 /var/www/base/app/Domain/Signal/Services/CodeOwnershipResolver.php(99): App\Foo->bar()
            #1 /var/www/base/app/Http/Controllers/SignalWebhookController.php(42): App\Bar->baz()
        TRACE;

        $owners = $this->resolver->resolve([
            'stack_trace' => $stackTrace,
            'code_owners_rules' => [
                ['pattern' => 'app/Domain/Signal/**', 'owners' => ['@signal-team']],
                ['pattern' => 'app/Http/Controllers/**', 'owners' => ['@platform-team']],
            ],
        ]);

        sort($owners);
        $this->assertSame(['@platform-team', '@signal-team'], $owners);
    }

    public function test_deduplicates_owners_across_sources(): void
    {
        $owners = $this->resolver->resolve([
            'code_owners' => ['@signal-team'],
            'affected_files' => ['app/Domain/Signal/Foo.php'],
            'code_owners_rules' => [
                ['pattern' => 'app/Domain/Signal/**', 'owners' => ['@signal-team']],
            ],
        ]);

        $this->assertSame(['@signal-team'], $owners);
    }

    public function test_ignores_malformed_rules(): void
    {
        $owners = $this->resolver->resolve([
            'affected_files' => ['app/Foo.php'],
            'code_owners_rules' => [
                'not-an-array',
                ['pattern' => 'app/**'],
                ['owners' => ['@x']],
                ['pattern' => 123, 'owners' => ['@x']],
                ['pattern' => 'app/**', 'owners' => 'not-an-array'],
            ],
        ]);

        $this->assertSame([], $owners);
    }
}
