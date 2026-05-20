# Architecture-test pattern for invariant enforcement

> **Status**: Established convention as of 2026-05-19 (livewire-authorize-sweep). This doc formalizes it as a first-class invariant-enforcement strategy.

## What it is

An **architecture test** is a PHPUnit test that scans the codebase for a security or design invariant and fails CI if a new violation appears. Unlike unit/feature tests (which exercise a behavior), an architecture test exercises a **rule about how code is written**.

The first instance is `tests/Feature/Architecture/LivewireAuthorizeCoverageTest.php`. It walks `app/Livewire/` and the cloud override `cloud/Livewire/`, finds every `public function` whose name starts with a write-prefix (`save`, `delete`, `toggle`, `approve`, …), and asserts each one either calls `Gate::authorize(…)` or appears on an explicit allowlist with a justification. New ungated write methods fail CI before merge.

This pattern is the right enforcement strategy for any invariant where:

1. The violation is **silent at runtime** — no exception, no test failure, just an exploitable hole.
2. The codebase has **>10 enforcement sites** — too many for code review to catch reliably.
3. The rule is **mechanical** — expressible as a regex or AST walk, not a human-judgment call.
4. **Onboarding new developers** would benefit from "CI told me what was wrong" over "the docs said so."

## When to use it

| Situation | Architecture test? | Why |
|---|---|---|
| One-off invariant (single file) | ❌ | A code comment is enough. |
| Convention with many call sites (>10) | ✅ | Catches drift; cheap once written. |
| Security gate that's silent on bypass | ✅ | The whole point. |
| Subjective "good code" rule | ❌ | Will produce false positives, get muted, then be useless. |
| Type/null-safety rule | ❌ | PHPStan / Larastan already does this better. |
| Rule depends on framework state at runtime | ❌ | Not statically inferable. |

## The pattern

Every architecture test follows the same five-section shape:

```php
class XxxCoverageTest extends TestCase
{
    // 1. WHAT TO LOOK FOR — name patterns / call-site patterns
    private const TARGET_PATTERNS = [...];

    // 2. WHAT TO IGNORE — lifecycle hooks, UI-only helpers, getters
    private const IGNORED_EXACT = [...];
    private const IGNORED_PREFIXES = [...];

    public function test_invariant_holds(): void
    {
        // 3. ALLOWLIST — explicit exemptions with reasons
        $allowlist = require __DIR__.'/xxx-allowlist.php';

        $violations = [];
        // 4. SCAN — walk relevant directories, collect violations
        foreach ($this->iterateTargetFiles() as $file) {
            foreach ($this->extractTargets($file) as $target => $body) {
                if (isset($allowlist[$target])) { continue; }
                if (! $this->isViolation($body))   { continue; }
                $violations[] = $target;
            }
        }

        // 5. ASSERT — actionable failure message
        $this->assertSame(
            [],
            $violations,
            "The following X are missing Y. Either add Y at the top of the method, "
            ."or add to xxx-allowlist.php with a justification.\n\n"
            ."Missing:\n  - ".implode("\n  - ", $violations),
        );
    }
}
```

The allowlist is a sibling PHP file returning `['FQCN::member' => 'reason string']`. The reason is mandatory — readers should learn from the allowlist, not just be silenced by it.

## Why allowlist over baseline

A traditional "baseline file" (PHPStan-style) silently absorbs all current violations and only catches *new* ones. That's the wrong model for security: the existing 145 ungated Livewire methods on 2026-05-04 weren't acceptable, they were "to be fixed." A baseline would let them stay forever.

The allowlist file is **smaller** than the baseline because the sweep happens first: you fix N violations until only the genuine pre-auth exemptions remain. The allowlist then holds only legitimate exemptions, each with a comment explaining why.

This is the strict-from-the-start enforcement that PHPStan baselines explicitly trade away for adoption speed. For security invariants, the trade is wrong.

## Sweep-then-test ordering

The pattern only works if you sweep first:

1. **Identify** the invariant. Write the scanner without an allowlist; let it report every violation.
2. **Sweep** — fix all violations across the codebase in one focused sprint (don't trickle).
3. **Allowlist** the genuine exemptions (typically <5 entries; if you're at 50, you're not really sweeping).
4. **Land** the test in the same PR as the sweep. From that moment on, regressions fail CI.

Trying to land the test before the sweep means either: (a) skip-listing 100+ entries (defeats the purpose), or (b) breaking CI for everyone (defeats adoption).

## When to retire an architecture test

When the codebase has evolved so that the invariant is now structurally enforced — e.g., a base class now requires the gate, so manual checking is moot. At that point delete the test; don't keep it as ceremony. But retire only after auditing that the structural enforcement actually replaces the mechanical check.

## Existing architecture tests

| Test | Invariant | Sweep date |
|---|---|---|
| `LivewireAuthorizeCoverageTest` | Every Livewire write method gates with `Gate::authorize` | 2026-05-19 |
| `ScopedExistsRuleCoverageTest` | Every `exists:<table>,<col>` validation rule on a tenant table scopes by `team_id` | 2026-05-20 |
| `McpToolTeamScopeCoverageTest` | Every MCP tool calling `withoutGlobalScopes` follows with `where('team_id', …)` or carries an `@mcp-cross-tenant <reason>` annotation | 2026-05-20 |

## Pre-sweep audit commands

For invariants where the violation count is too large to sweep in a single sprint, ship an audit command first. The command produces the punch-list; the architecture test lands after the sweep completes.

| Command | Invariant | Notes |
|---|---|---|
| `php artisan audit:mcp-team-scope` | Same as `McpToolTeamScopeCoverageTest` but printable per-domain | Kept after sweep for ad-hoc reporting (`--format=summary`, `--domain=X`) |

## MCP team-scope sweep — accepted annotations

The 2026-05-20 sweep classified all 352 MCP tools. Cross-tenant intent is signalled by one of these `@mcp-cross-tenant <reason>` markers:

| Annotation | When to use |
|---|---|
| `super-admin` | Tool is gated by SuperAdmin middleware; access spans all teams |
| `marketplace-public-read` | Marketplace listings span teams by design; ownership check in method body |
| `platform-discovery` | Lists platform-wide resources (e.g. all gateway-exposed workflows) |
| `platform-tool-activation` | Platform tools are global; `isPlatformTool()` gates non-platform |
| `cross-tenant-discovery` | First lookup is a platform seed (e.g. listing by slug); per-team queries follow |
| `transitive-via-<entity>` | Parent FK (e.g. `agent_id`) is team-verified upstream in the same method |
| `team-self-lookup` | `Team::find($teamId)` where `$teamId` is `auth()->user()?->current_team_id` |
| `team-id-in-update-or-create` | `updateOrCreate` / `firstOrCreate` match keys include `team_id` |

If none of these fit, fix the query: add `->where('team_id', $teamId)` after `withoutGlobalScopes()`.

Related Serena memory: `feedback/mcp-tool-unconditional-team-scope`.

## Related
- `feedback/livewire-authorize-coverage-test` (Serena)
- `sprints/livewire-authorize-sweep-2026-05-19` (Serena)
- `tests/Feature/Architecture/LivewireAuthorizeCoverageTest.php`
- `tests/Feature/Architecture/livewire-authorize-allowlist.php`
