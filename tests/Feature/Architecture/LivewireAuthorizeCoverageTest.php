<?php

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Architectural test: every Livewire write method must either call Gate::authorize
 * (or update-self for per-user forms) or be on the explicit allowlist.
 *
 * Why: missing-authorize gaps caused the security sweep sprint on 2026-05-04.
 * This test prevents regression — a future Livewire form with a save() that
 * forgets authorize will fail this test before merge.
 */
class LivewireAuthorizeCoverageTest extends TestCase
{
    /**
     * Method-name prefixes that count as "write" methods (case-sensitive).
     * Anything starting with one of these AND not in the IGNORED_PREFIXES list gets checked.
     */
    private const WRITE_PREFIXES = [
        'save',
        'delete',
        'toggle',
        'activate', 'deactivate', 'pause', 'resume', 'archive', 'restart',
        'publish', 'unpublish',
        'connect', 'disconnect',
        'generate',
        'rotate', 'revoke', 'approve', 'reject',
        'add', 'remove',  // remove is borderline — see IGNORED below
        'register', 'enable', 'disable',
        'trigger', 'execute', 'run',
        'set', 'unset',
        'sync', 'import', 'export',
        'deploy',
        'test',
        'create',
        'store',
    ];

    /**
     * Method-name prefixes/exact names that LOOK like writes but aren't.
     * Livewire lifecycle hooks (`updated*`), UI helpers (`removeRow`, `addRow`),
     * pre-save state mutations (`toggleWorker` for crew member selection),
     * and getters (`get*`).
     */
    private const IGNORED_EXACT = [
        // UI-only state mutations (no DB writes)
        'addCustomPair', 'removeCustomPair',
        'addRotateCustomPair', 'removeRotateCustomPair',
        'addConditionRow', 'removeConditionRow',
        'addMappingRow', 'removeMappingRow',
        'addMilestone', 'removeMilestone',
        'addDependency', 'removeDependency',
        'addInputField', 'removeInputField',
        'addOutputField', 'removeOutputField',
        'addConsensusModel', 'removeConsensusModel',
        'addFallback', 'removeFallback',
        'removeCredential', // CreateCredentialForm: removes from in-memory pairs
        'toggleWorker', 'toggleSkill', 'toggleTool', 'toggleGitRepository', // pre-save selection state
        'cancelForm', 'cancelEdit', 'cancelRotate', 'cancelMemberPolicy',
        'cancelBenchmark', 'cancelTelegramForm',
        'startEdit', 'startRotate', 'startBenchmark', 'startTelegramEdit',
        'startEditMemberPolicy', 'openModal', 'openForm',
        'resetForm', 'resetFields',
        'setActiveTab', 'switchTab',
        // Display-only computed
        'render',
    ];

    private const IGNORED_PREFIXES = [
        // Livewire reactive hooks — fire on wire:model change, not user submit
        'updated',
        // Lifecycle
        'mount', 'boot', 'hydrate', 'dehydrate', 'placeholder',
        // Getters
        'get',
        // Loaders / pure-read helpers (only ones that explicitly don't write)
        'load', 'fetch',
        // Validation helpers
        'validateOnly', 'rules',
    ];

    public function test_every_livewire_write_method_calls_authorize(): void
    {
        $allowlist = require __DIR__.'/livewire-authorize-allowlist.php';
        $missing = [];

        $livewireRoot = base_path('app/Livewire');
        if (! is_dir($livewireRoot)) {
            $this->markTestSkipped('No app/Livewire directory.');
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($livewireRoot));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $namespace = $this->extractNamespace($contents);
            $class = $this->extractClassName($contents);
            if ($namespace === null || $class === null) {
                continue;
            }
            $fqcn = $namespace.'\\'.$class;

            foreach ($this->extractWriteMethods($contents) as $method => $body) {
                $key = $fqcn.'::'.$method;
                if (array_key_exists($key, $allowlist)) {
                    continue;
                }

                // Skip methods that don't actually write to the database — pure
                // UI-state setters (e.g. setActiveTab) match write-prefix names
                // but never touch persistence. Only flag methods whose body
                // contains a DB-write signal.
                if (! $this->hasDatabaseWrite($body)) {
                    continue;
                }

                if ($this->hasAuthorization($body)) {
                    continue;
                }

                $missing[] = $key;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'The following Livewire write methods are missing Gate::authorize. '
            ."Either add Gate::authorize('edit-content'|'manage-team'|'update-self') "
            .'at the top of the method, or add the method to '
            ."tests/Feature/Architecture/livewire-authorize-allowlist.php with a justification.\n\n"
            ."Missing:\n  - ".implode("\n  - ", $missing),
        );
    }

    private function extractNamespace(string $contents): ?string
    {
        if (preg_match('/^namespace\s+([^;]+);/m', $contents, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractClassName(string $contents): ?string
    {
        if (preg_match('/^class\s+(\w+)/m', $contents, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array<string, string> method name → method body
     */
    private function extractWriteMethods(string $contents): array
    {
        $methods = [];
        $lines = explode("\n", $contents);
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            if (! preg_match('/^\s*public\s+function\s+(\w+)\s*\(/', $lines[$i], $m)) {
                continue;
            }
            $methodName = $m[1];

            if (! $this->isWriteMethod($methodName)) {
                continue;
            }

            // Find body — collect until matching `    }` at indent 4
            $bodyLines = [];
            $depth = 0;
            $started = false;
            for ($j = $i; $j < $count; $j++) {
                $line = $lines[$j];
                $bodyLines[] = $line;
                $depth += substr_count($line, '{') - substr_count($line, '}');
                if ($depth > 0) {
                    $started = true;
                }
                if ($started && $depth === 0) {
                    break;
                }
            }
            $methods[$methodName] = implode("\n", $bodyLines);
        }

        return $methods;
    }

    /**
     * Detect any of the common authorization patterns. Either:
     *   - Gate::authorize('...')                  — preferred
     *   - $this->authorize('...')                 — Livewire trait helper
     *   - Gate::allows(...)                       — explicit if-not check
     *   - abort_unless(Gate::check(...), 403)    — older pattern; equivalent
     *   - abort_if(! Gate::allows(...), 403)
     *   - $this->authorizeForUser(...)
     */
    private function hasAuthorization(string $body): bool
    {
        return (bool) preg_match(
            '/Gate::(authorize|allows|check|denies)|->authorize\(|abort_unless\s*\(\s*Gate::|abort_if\s*\([^)]*Gate::/',
            $body,
        );
    }

    /**
     * Detect DB-write signals — Eloquent persistence calls or Action::execute().
     * Methods without any of these are UI-state setters and don't need authorize.
     */
    private function hasDatabaseWrite(string $body): bool
    {
        return (bool) preg_match(
            '/->(save|update|delete|push|forceDelete|restore|associate|attach|detach|sync|saveQuietly|increment|decrement)\(|::create\(|::updateOrCreate\(|::firstOrCreate\(|::insert\(|::query\(\)->(update|delete|insert)\(|->execute\(|->dispatch\(|dispatch\(/',
            $body,
        );
    }

    private function isWriteMethod(string $name): bool
    {
        if (in_array($name, self::IGNORED_EXACT, true)) {
            return false;
        }
        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return false;
            }
        }
        foreach (self::WRITE_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
