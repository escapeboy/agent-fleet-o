<?php

namespace App\Domain\Signal\Services;

/**
 * Resolves probable code owners from a signal payload.
 *
 * Pattern borrowed from prilog.ai: injecting "who owns the failing code path"
 * alongside the signal so TriggerRules can declaratively route the experiment
 * to the right team or stakeholder.
 *
 * The resolver is intentionally lightweight — it looks at three sources in
 * the payload:
 *   1. `code_owners` (explicit, when the upstream caller already knows)
 *   2. `file_paths` / `affected_files` cross-referenced against the payload's
 *      `code_owners_rules` (CODEOWNERS-style pattern list)
 *   3. `stack_trace` (extracts file paths, then runs through the rules)
 *
 * Returns a normalised, de-duplicated list of owner identifiers (GitHub
 * handles, email addresses, team slugs — whatever the rules emitted).
 */
class CodeOwnershipResolver
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    public function resolve(array $payload): array
    {
        $owners = [];

        if (! empty($payload['code_owners']) && is_array($payload['code_owners'])) {
            foreach ($payload['code_owners'] as $owner) {
                if (is_string($owner) && $owner !== '') {
                    $owners[] = $owner;
                }
            }
        }

        $rules = $this->extractRules($payload);
        $files = $this->extractFiles($payload);

        if (! empty($rules) && ! empty($files)) {
            foreach ($files as $file) {
                foreach ($this->matchOwners($file, $rules) as $owner) {
                    $owners[] = $owner;
                }
            }
        }

        return array_values(array_unique($owners));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{pattern: string, owners: array<int, string>}>
     */
    private function extractRules(array $payload): array
    {
        $raw = $payload['code_owners_rules'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $rules = [];
        foreach ($raw as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $pattern = $rule['pattern'] ?? null;
            $owners = $rule['owners'] ?? null;
            if (! is_string($pattern) || ! is_array($owners)) {
                continue;
            }
            $ownersFiltered = array_values(array_filter(
                $owners,
                fn ($o) => is_string($o) && $o !== '',
            ));
            if (empty($ownersFiltered)) {
                continue;
            }
            $rules[] = ['pattern' => $pattern, 'owners' => $ownersFiltered];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function extractFiles(array $payload): array
    {
        $files = [];

        foreach (['affected_files', 'file_paths', 'files'] as $key) {
            if (! empty($payload[$key]) && is_array($payload[$key])) {
                foreach ($payload[$key] as $file) {
                    if (is_string($file) && $file !== '') {
                        $files[] = $file;
                    }
                }
            }
        }

        $stackTrace = $payload['stack_trace'] ?? null;
        if (is_string($stackTrace) && $stackTrace !== '') {
            // Match common file path patterns inside a stack trace, e.g.
            //   "at app/Domain/Signal/Models/Signal.php:42"
            //   "  File \"src/handlers/index.ts\", line 17"
            //   "  /var/www/base/app/Foo.php(99): ..."
            preg_match_all('#(?:^|[\s"\(])([a-zA-Z0-9_./\-]+\.(?:php|py|js|ts|tsx|jsx|rb|go|java|rs|kt|swift|cs|cpp|c|h|m|mm))#m', $stackTrace, $matches);
            foreach ($matches[1] ?? [] as $file) {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param  array<int, array{pattern: string, owners: array<int, string>}>  $rules
     * @return array<int, string>
     */
    private function matchOwners(string $file, array $rules): array
    {
        $matched = [];
        foreach ($rules as $rule) {
            if ($this->matchesPattern($file, $rule['pattern'])) {
                foreach ($rule['owners'] as $owner) {
                    $matched[] = $owner;
                }
            }
        }

        return $matched;
    }

    /**
     * CODEOWNERS-style glob: `*` matches any path segment chars, `**` matches
     * across path separators, leading `/` anchors to repo root, trailing `/`
     * matches directory + descendants.
     */
    private function matchesPattern(string $file, string $pattern): bool
    {
        $normalisedFile = ltrim($file, '/');
        $anchored = str_starts_with($pattern, '/');
        $pattern = ltrim($pattern, '/');

        if (str_ends_with($pattern, '/')) {
            $pattern .= '**';
        }

        $regex = $this->globToRegex($pattern);

        if ($anchored) {
            return (bool) preg_match('#^'.$regex.'$#', $normalisedFile);
        }

        return (bool) preg_match('#(?:^|/)'.$regex.'$#', $normalisedFile);
    }

    private function globToRegex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '*') {
                if (isset($pattern[$i + 1]) && $pattern[$i + 1] === '*') {
                    $regex .= '.*';
                    $i++;
                } else {
                    $regex .= '[^/]*';
                }
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } elseif (in_array($char, ['.', '+', '(', ')', '|', '^', '$', '{', '}', '[', ']', '\\'], true)) {
                $regex .= '\\'.$char;
            } else {
                $regex .= $char;
            }
        }

        return $regex;
    }
}
