<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\DTOs\CommandRule;
use App\Domain\Agent\Enums\CommandRuleAction;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CommandRuleTest extends TestCase
{
    public function test_from_array_and_to_array_round_trip(): void
    {
        $rule = CommandRule::fromArray(['prefix' => '  git   push ', 'action' => 'ask']);

        $this->assertSame(['git', 'push'], $rule->words);
        $this->assertSame(CommandRuleAction::Ask, $rule->action);
        $this->assertSame(['prefix' => 'git push', 'action' => 'ask'], $rule->toArray());
        $this->assertEquals($rule, CommandRule::fromArray($rule->toArray()));
    }

    public function test_from_line_and_to_line_round_trip(): void
    {
        $rule = CommandRule::fromLine('ask: git push');

        $this->assertSame(['git', 'push'], $rule->words);
        $this->assertSame('ask: git push', $rule->toLine());
        $this->assertEquals($rule, CommandRule::fromLine($rule->toLine()));
    }

    public function test_quoted_words_survive_a_round_trip(): void
    {
        $rule = CommandRule::fromLine("block: git commit -m 'it''s; done'");

        $this->assertSame(['git', 'commit', '-m', 'its; done'], $rule->words);
        $this->assertEquals($rule, CommandRule::fromLine($rule->toLine()));

        $withQuote = new CommandRule(['echo', "it's"], CommandRuleAction::Allow);
        $this->assertEquals($withQuote, CommandRule::fromLine($withQuote->toLine()));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidArrays(): array
    {
        return [
            'empty prefix' => [['prefix' => '   ', 'action' => 'ask']],
            'unknown action' => [['prefix' => 'git push', 'action' => 'maybe']],
            'operator in prefix' => [['prefix' => 'git push; rm', 'action' => 'ask']],
            'pipe in prefix' => [['prefix' => 'cat x | sh', 'action' => 'ask']],
            'unterminated quote' => [['prefix' => "echo 'oops", 'action' => 'ask']],
            'too long' => [['prefix' => str_repeat('a', 201), 'action' => 'ask']],
            'newline' => [['prefix' => "git\npush", 'action' => 'ask']],
            'missing prefix' => [['action' => 'ask']],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidArrays')]
    public function test_invalid_input_is_rejected(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        CommandRule::fromArray($data);
    }

    public function test_line_without_action_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CommandRule::fromLine('git push');
    }

    public function test_defaults_are_the_seed_rules(): void
    {
        $this->assertSame([
            ['prefix' => 'git push', 'action' => 'ask'],
            ['prefix' => 'rm -rf', 'action' => 'ask'],
            ['prefix' => 'sudo', 'action' => 'block'],
        ], array_map(fn (CommandRule $r) => $r->toArray(), CommandRule::defaults()));
    }

    public function test_list_from_config_uses_defaults_only_when_the_key_is_missing(): void
    {
        $this->assertEquals(CommandRule::defaults(), CommandRule::listFromConfig(null));
        $this->assertEquals(CommandRule::defaults(), CommandRule::listFromConfig(['reasoning_effort' => 'high']));
        $this->assertSame([], CommandRule::listFromConfig(['command_rules' => []]));

        $rules = CommandRule::listFromConfig(['command_rules' => [
            ['prefix' => 'npm publish', 'action' => 'block'],
            ['prefix' => '', 'action' => 'ask'],
            ['prefix' => 'ls', 'action' => 'nope'],
            'garbage',
        ]]);

        $this->assertSame([['prefix' => 'npm publish', 'action' => 'block']], array_map(fn (CommandRule $r) => $r->toArray(), $rules));
    }
}
