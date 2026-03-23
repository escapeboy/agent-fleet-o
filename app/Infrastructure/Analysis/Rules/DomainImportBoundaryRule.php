<?php

namespace App\Infrastructure\Analysis\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Enforces that domain classes never import from the presentation or MCP layers.
 *
 * Files under app/Domain/ must not use:
 *   - App\Http\      (controllers, middleware, requests)
 *   - App\Livewire\  (UI components)
 *   - App\Mcp\       (MCP server / tools)
 *
 * This keeps domain logic independent of delivery mechanisms, making it
 * safe to use from any transport (HTTP, CLI, queue workers, tests).
 *
 * @implements Rule<Use_>
 */
final class DomainImportBoundaryRule implements Rule
{
    private const FORBIDDEN_PREFIXES = [
        'App\\Http\\',
        'App\\Livewire\\',
        'App\\Mcp\\',
    ];

    public function getNodeType(): string
    {
        return Use_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! str_contains($scope->getFile(), '/app/Domain/')) {
            return [];
        }

        $errors = [];

        foreach ($node->uses as $use) {
            $name = $use->name->toString();

            foreach (self::FORBIDDEN_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $errors[] = RuleErrorBuilder::message(
                        "Domain class must not import from the presentation or MCP layer: {$name}. "
                        .'Move shared logic to app/Domain/ or app/Infrastructure/ instead.',
                    )->identifier('domain.importBoundary')->build();
                }
            }
        }

        return $errors;
    }
}
