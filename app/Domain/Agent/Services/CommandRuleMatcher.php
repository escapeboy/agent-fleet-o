<?php

namespace App\Domain\Agent\Services;

use App\Domain\Agent\DTOs\CommandRule;
use App\Domain\Agent\Enums\CommandRuleAction;
use App\Infrastructure\AI\Gateways\LocalAgentOutputParser;
use InvalidArgumentException;

/**
 * Decides Allow/Ask/Block for a shell command against per-agent CommandRules.
 * A chained command (;, &&, ||, |, &, ( ), newline) is checked one part at a
 * time; per part the longest matching word prefix wins (ties: most severe);
 * the whole command gets the most severe part's result. Pure: no database
 * or container use.
 */
final class CommandRuleMatcher
{
    /** Wrappers dropped before matching (sudo is kept so it can be ruled on). */
    private const WRAPPERS = ['env', 'time', 'nohup'];

    /**
     * Splits a command into its parts, each a list of raw (still quoted)
     * words. Redirections and their targets are left out. Returns null on an
     * unterminated quote.
     *
     * @return list<list<string>>|null
     */
    public function segments(string $command): ?array
    {
        $parts = [];
        $current = [];
        $pos = 0;
        $len = strlen($command);

        while (true) {
            while ($pos < $len && ctype_space($command[$pos])) {
                if ($command[$pos] === "\n") {
                    self::endPart($parts, $current);
                }
                $pos++;
            }
            if ($pos >= $len) {
                break;
            }

            $word = LocalAgentOutputParser::nextShellWord($command, $pos, $len);
            if ($word === null) {
                return null;
            }
            [$raw] = $word;

            if ($raw !== '') {
                // A file descriptor number glued to a redirection (2>&1, 1>file).
                if ($pos < $len && ($command[$pos] === '<' || $command[$pos] === '>') && ctype_digit($raw)) {
                    continue;
                }
                $current[] = $raw;

                continue;
            }

            $c = $command[$pos];
            $isRedirect = $c === '<' || $c === '>'
                || ($c === '&' && $pos + 1 < $len && $command[$pos + 1] === '>');

            if (! $isRedirect) {
                // ; && || | & ( ) end the current part.
                $pos++;
                self::endPart($parts, $current);

                continue;
            }

            // Redirection: consume the operator (<, >, >>, <<, &>, >&, >|, <>)
            // and skip its target word.
            if ($c === '&') {
                $pos++;
            }
            while ($pos < $len && ($command[$pos] === '<' || $command[$pos] === '>')) {
                $pos++;
            }
            if ($pos < $len && ($command[$pos] === '&' || $command[$pos] === '|')) {
                $pos++;
            }
            while ($pos < $len && $command[$pos] !== "\n" && ctype_space($command[$pos])) {
                $pos++;
            }
            if ($pos < $len && $command[$pos] !== "\n"
                && LocalAgentOutputParser::nextShellWord($command, $pos, $len) === null) {
                return null;
            }
        }
        self::endPart($parts, $current);

        return $parts;
    }

    /**
     * @param  list<list<string>>  $parts
     * @param  list<string>  $current
     */
    private static function endPart(array &$parts, array &$current): void
    {
        if ($current !== []) {
            $parts[] = $current;
        }
        $current = [];
    }

    /**
     * @param  list<CommandRule>  $rules
     * @param  list<string>  $grants  word prefixes that turn a rule's Ask into Allow
     */
    public function decide(
        string $command,
        array $rules,
        array $grants = [],
        CommandRuleAction $default = CommandRuleAction::Allow,
    ): CommandRuleAction {
        if (trim($command) === '') {
            return $default;
        }

        $parts = $this->segments($command);
        if ($parts === null) {
            return CommandRuleAction::Ask;
        }
        if ($parts === []) {
            return $default;
        }

        $grantWords = [];
        foreach ($grants as $grant) {
            try {
                $grantWords[] = CommandRule::words($grant);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        $result = CommandRuleAction::Allow;
        foreach ($parts as $rawWords) {
            $result = $result->atLeast($this->decidePart($rawWords, $rules, $grantWords, $default));
        }

        return $result;
    }

    /**
     * @param  list<string>  $rawWords
     * @param  list<CommandRule>  $rules
     * @param  list<list<string>>  $grantWords
     */
    private function decidePart(array $rawWords, array $rules, array $grantWords, CommandRuleAction $default): CommandRuleAction
    {
        $words = $this->normalise($rawWords);

        $decision = $default;
        $bestLength = 0;
        foreach ($rules as $rule) {
            if (! self::isPrefix($rule->words, $words)) {
                continue;
            }
            $length = count($rule->words);
            if ($length > $bestLength) {
                $bestLength = $length;
                $decision = $rule->action;
            } elseif ($length === $bestLength) {
                $decision = $decision->atLeast($rule->action);
            }
        }

        if ($decision === CommandRuleAction::Ask) {
            foreach ($grantWords as $grant) {
                if (self::isPrefix($grant, $words)) {
                    $decision = CommandRuleAction::Allow;
                    break;
                }
            }
        }

        // Command substitution hides a command from the matcher: never Allow it.
        foreach ($rawWords as $raw) {
            if (str_contains($raw, '`') || str_contains($raw, '$(')) {
                return $decision->atLeast(CommandRuleAction::Ask);
            }
        }

        return $decision;
    }

    /**
     * Unquoted words with leading NAME=value assignments and env/time/nohup
     * dropped and the program reduced to its basename.
     *
     * @param  list<string>  $rawWords
     * @return list<string>
     */
    private function normalise(array $rawWords): array
    {
        $words = [];
        $leading = true;
        foreach ($rawWords as $raw) {
            $pos = 0;
            $parsed = LocalAgentOutputParser::nextShellWord($raw, $pos, strlen($raw));
            $text = $parsed[1] ?? $raw;

            if ($leading) {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\[[^\]]*\])?\+?=/', $raw) === 1
                    || in_array($text, self::WRAPPERS, true)) {
                    continue;
                }
                $leading = false;
                $slash = strrpos($text, '/');
                if ($slash !== false && $slash < strlen($text) - 1) {
                    $text = substr($text, $slash + 1);
                }
            }
            $words[] = $text;
        }

        return $words;
    }

    /**
     * @param  list<string>  $prefix
     * @param  list<string>  $words
     */
    private static function isPrefix(array $prefix, array $words): bool
    {
        if ($prefix === [] || count($prefix) > count($words)) {
            return false;
        }

        return array_slice($words, 0, count($prefix)) === $prefix;
    }
}
