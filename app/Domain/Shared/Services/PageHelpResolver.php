<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use Illuminate\Support\Facades\Log;

/**
 * Resolves contextual page-help content per route, allowing dynamic
 * overrides that read the current entity state.
 *
 * Static help: `config('page-help.{routeName}')` — the original behavior.
 * Dynamic help: `config('page-help-dynamic.{routeName}')` — a class name
 * that implements __invoke(array $routeParameters): array. The returned
 * array is merged ON TOP of the static config (override semantics).
 *
 * The merge is shallow: dynamic resolvers can replace the description,
 * steps, tips, prerequisites, or related sections wholesale, but cannot
 * patch individual entries inside the steps list. Callers that want
 * deeper composition should build the full sub-array themselves.
 *
 * Falls back gracefully: a missing or throwing dynamic resolver does not
 * break the page — static help still renders.
 */
final class PageHelpResolver
{
    /**
     * @param  array<string, mixed>  $routeParameters  bound route models / scalars,
     *                                                 e.g. ['agent' => Agent$instance]
     * @return array<string, mixed>|null
     */
    public function resolve(string $routeName, array $routeParameters = []): ?array
    {
        // Direct flat-key lookup. The page-help and page-help-dynamic configs
        // use dotted route names ("experiments.show") as flat top-level keys,
        // which the standard `config('page-help.experiments.show')` dot-path
        // accessor cannot traverse. We pull the full file array and index it
        // directly so the lookup works regardless of dots in route names.
        $staticAll = config('page-help', []);
        $dynamicAll = config('page-help-dynamic', []);

        $static = is_array($staticAll) ? ($staticAll[$routeName] ?? null) : null;
        $dynamicDef = is_array($dynamicAll) ? ($dynamicAll[$routeName] ?? null) : null;

        if ($dynamicDef === null) {
            return is_array($static) ? $static : null;
        }

        $overrides = $this->invokeDynamicResolver($dynamicDef, $routeParameters);

        if ($overrides === null) {
            return is_array($static) ? $static : null;
        }

        return is_array($static)
            ? array_replace($static, $overrides)
            : $overrides;
    }

    /**
     * @param  string|callable  $resolver
     * @param  array<string, mixed>  $routeParameters
     * @return array<string, mixed>|null
     */
    private function invokeDynamicResolver(mixed $resolver, array $routeParameters): ?array
    {
        try {
            if (is_string($resolver) && class_exists($resolver)) {
                $instance = app($resolver);
                if (! is_callable($instance)) {
                    return null;
                }
                $result = $instance($routeParameters);

                return is_array($result) ? $result : null;
            }

            if (is_callable($resolver)) {
                $result = $resolver($routeParameters);

                return is_array($result) ? $result : null;
            }
        } catch (\Throwable $e) {
            // Dynamic help failures must NEVER break the page. Log once and
            // fall through to static-only help.
            Log::warning('PageHelpResolver: dynamic resolver failed', [
                'resolver' => is_string($resolver) ? $resolver : 'closure',
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
