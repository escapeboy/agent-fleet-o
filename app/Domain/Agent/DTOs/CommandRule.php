<?php

namespace App\Domain\Agent\DTOs;

use App\Domain\Agent\Enums\CommandRuleAction;
use App\Infrastructure\AI\Gateways\LocalAgentOutputParser;
use InvalidArgumentException;

/**
 * One per-agent shell command rule: a word prefix ("git push") and what to do
 * with a command that starts with it. Stored in agents.config.command_rules as
 * a list of {prefix, action}. See CommandRuleMatcher for how rules are applied.
 */
final readonly class CommandRule
{
    public const MAX_PREFIX_LENGTH = 200;

    /**
     * @param  list<string>  $words
     */
    public function __construct(
        public array $words,
        public CommandRuleAction $action,
    ) {
        if ($words === []) {
            throw new InvalidArgumentException('Command prefix must not be empty.');
        }
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $prefix = $data['prefix'] ?? null;
        $action = $data['action'] ?? null;
        if (! is_string($prefix)) {
            throw new InvalidArgumentException('Command prefix must be a string.');
        }
        if (! is_string($action)) {
            throw new InvalidArgumentException('Action must be one of allow, ask, block.');
        }

        return new self(self::words($prefix), self::parseAction($action));
    }

    /**
     * @return array{prefix: string, action: string}
     */
    public function toArray(): array
    {
        return ['prefix' => $this->prefix(), 'action' => $this->action->value];
    }

    /**
     * Parses "<action>: <prefix>", e.g. "ask: git push".
     */
    public static function fromLine(string $line): self
    {
        $colon = strpos($line, ':');
        if ($colon === false) {
            throw new InvalidArgumentException('Expected "allow|ask|block: command prefix".');
        }

        return new self(
            self::words(substr($line, $colon + 1)),
            self::parseAction(substr($line, 0, $colon)),
        );
    }

    public function toLine(): string
    {
        return $this->action->value.': '.$this->prefix();
    }

    /**
     * The prefix as shell words; words that need it are single-quoted so the
     * result parses back to the same words.
     */
    public function prefix(): string
    {
        return implode(' ', array_map(
            fn (string $word): string => preg_match('/^[A-Za-z0-9_.\/:=@%+,-]+$/', $word) === 1
                ? $word
                : "'".str_replace("'", "'\"'\"'", $word)."'",
            $this->words,
        ));
    }

    /**
     * The seed rules an agent gets when it has no command_rules of its own.
     *
     * @return list<self>
     */
    public static function defaults(): array
    {
        return [
            new self(['git', 'push'], CommandRuleAction::Ask),
            new self(['rm', '-rf'], CommandRuleAction::Ask),
            new self(['sudo'], CommandRuleAction::Block),
        ];
    }

    /**
     * Rules for an agent config: defaults() when the command_rules key is
     * missing; otherwise the stored list with invalid entries skipped.
     *
     * @param  array<mixed>|null  $agentConfig
     * @return list<self>
     */
    public static function listFromConfig(?array $agentConfig): array
    {
        if ($agentConfig === null || ! array_key_exists('command_rules', $agentConfig) || ! is_array($agentConfig['command_rules'])) {
            return self::defaults();
        }

        $rules = [];
        foreach ($agentConfig['command_rules'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            try {
                $rules[] = self::fromArray($entry);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $rules;
    }

    /**
     * Splits a command prefix into its unquoted words. Rejects an empty
     * prefix, one over MAX_PREFIX_LENGTH, an unquoted operator or newline
     * and an unterminated quote.
     *
     * @return list<string>
     */
    public static function words(string $prefix): array
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            throw new InvalidArgumentException('Command prefix must not be empty.');
        }
        if (strlen($prefix) > self::MAX_PREFIX_LENGTH) {
            throw new InvalidArgumentException('Command prefix must be at most '.self::MAX_PREFIX_LENGTH.' characters.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $prefix) === 1) {
            throw new InvalidArgumentException('Command prefix must be a single line.');
        }

        $words = [];
        $pos = 0;
        $len = strlen($prefix);
        while (true) {
            $word = LocalAgentOutputParser::nextShellWord($prefix, $pos, $len);
            if ($word === null) {
                throw new InvalidArgumentException('Command prefix has an unterminated quote.');
            }
            if ($word[0] === '') {
                if ($pos < $len) {
                    throw new InvalidArgumentException('Command prefix must not contain shell operators (; & | < > ( )).');
                }
                break;
            }
            $words[] = $word[1];
        }

        return $words;
    }

    private static function parseAction(string $action): CommandRuleAction
    {
        return CommandRuleAction::tryFrom(strtolower(trim($action)))
            ?? throw new InvalidArgumentException('Action must be one of allow, ask, block.');
    }
}
