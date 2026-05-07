<?php

namespace App\Domain\Assistant\Tools;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

/**
 * Boruna-specific assistant tools — currently a single generator that turns
 * natural-language descriptions into syntactically-valid `.ax` source.
 *
 * Lives in the WRITE tier (member+) — generating code is a non-destructive
 * but state-affecting action.
 */
class BorunaTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function tools(): array
    {
        return [
            self::generateBorunaScript(),
        ];
    }

    private static function generateBorunaScript(): PrismToolObject
    {
        return PrismTool::as('generate_boruna_script')
            ->for('Generate a Boruna .ax program from a natural-language description. Use when the user wants a deterministic skill (parsing, validation, transformation, hashing, etc.) and would benefit from running .ax on the bundled Boruna VM at zero LLM cost. Returns syntactically-valid .ax source ready to paste into a boruna_script Skill. The user is still responsible for picking a capability policy.')
            ->withStringParameter('description', 'Natural-language description of what the script should do — e.g. "validate that input is a non-empty email address" or "compute the SHA-256 of the input string".')
            ->withStringParameter('input_hint', 'Optional: type or shape of the input the script will receive — e.g. "String", "Int", "Map<String, Int>". Boruna .ax has no implicit input channel, so authors typically interpolate inputs as literals before each run.')
            ->using(function (string $description, ?string $input_hint = null) {
                $teamId = (string) (auth()->user()->current_team_id ?? '');

                $userMessage = "Description:\n{$description}";
                if ($input_hint) {
                    $userMessage .= "\n\nInput hint: {$input_hint}";
                }

                try {
                    $response = app(AiGatewayInterface::class)->complete(new AiRequestDTO(
                        provider: 'anthropic',
                        model: 'claude-haiku-4-5',
                        systemPrompt: self::axSystemPrompt(),
                        userPrompt: $userMessage,
                        teamId: $teamId,
                        purpose: 'boruna_script_generation',
                        maxTokens: 1024,
                    ));

                    return json_encode([
                        'description' => $description,
                        'input_hint' => $input_hint,
                        'script' => self::stripCodeFence((string) $response->content),
                        'next_steps' => 'Paste the script into a boruna_script Skill. Choose a capability policy: deny-all (safest, refuses any side effects) or a structured Policy object (per-capability allow/budget rules) — see docs/reference/policy-schema.md upstream.',
                    ]);
                } catch (\Throwable $e) {
                    return json_encode([
                        'error' => 'generation_failed',
                        'message' => $e->getMessage(),
                    ]);
                }
            });
    }

    /**
     * Compact crash course in .ax for the LLM. Kept terse on purpose —
     * Haiku is good but pays per token, and the language fits in a screen.
     * The emphasis is on the single non-obvious facts: explicit types,
     * mandatory `fn main() -> Int { ... }` entry point, no `return`,
     * capability annotations using `!{...}` after the return type.
     */
    private static function axSystemPrompt(): string
    {
        return <<<'PROMPT'
You generate Boruna `.ax` source code. Output ONLY the `.ax` source — no commentary, no Markdown fences, no explanation. The user will paste the result directly into a Skill editor.

`.ax` rules you MUST follow:
- Statically typed, immutable, deterministic. Compiles to Boruna bytecode.
- Every standalone file MUST define `fn main() -> ReturnType { … }`. No exceptions.
- Variables are immutable. Use `let name: Type = value`. NO mutation, NO loops over mutable counters.
- No semicolons. Each statement is on its own line. No trailing semicolons.
- No `return` keyword. The last expression in a block IS the return value.
- Types: `Int` (i64), `Float` (f64), `String`, `Bool`, `Unit` (`()`), `Option<T>`, `Result<T, E>`, `List<T>`, `Map<K, V>`. Records via `record Name { field: Type, … }`. Enums via `enum Name { Variant { field: Type } }`.
- Pattern matching: `match x { Some(y) => …, None => … }`.
- Side effects MUST be declared via capability annotations on the function signature: `fn fetch(url: String) -> String !{net.fetch}`. Capabilities: `net.fetch`, `fs.read`, `fs.write`, `db.query`, `ui.render`, `time.now`, `random`, `llm.call`, `actor.spawn`, `actor.send`. If a function does not need a capability, do not declare one.
- For pure transformations (the common case), `fn main() -> ReturnType` needs NO capability annotation.
- There is no implicit "input" parameter. If runtime data is needed, the calling system interpolates it as literals before submitting the script.

Style:
- Prefer pure expressions over intermediate `let` bindings unless naming improves readability.
- Use `match` for branching on Option/Result/enum.
- Use the standard library methods you'd reasonably expect (e.g. `String` length, list ops). If you're unsure a method exists, fall back to a simpler primitive expression that is definitely valid.

Examples of valid main signatures:
- `fn main() -> Int { 42 }`
- `fn main() -> String { "hello" }`
- `fn main() -> Bool { let n: Int = 7; n > 0 }`

Output the `.ax` source ONLY.
PROMPT;
    }

    /**
     * Strip a leading/trailing Markdown code fence if the model accidentally
     * wraps its output. We tolerate the model's mistake rather than fail the
     * caller — generation is best-effort UX.
     */
    private static function stripCodeFence(string $source): string
    {
        $source = trim($source);

        if (preg_match('/^```(?:ax|rust)?\s*\n(.*?)\n```\s*$/s', $source, $m)) {
            return trim($m[1]);
        }

        return $source;
    }
}
