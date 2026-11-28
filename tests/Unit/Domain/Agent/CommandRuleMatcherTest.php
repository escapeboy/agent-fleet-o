<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\DTOs\CommandRule;
use App\Domain\Agent\Enums\CommandRuleAction;
use App\Domain\Agent\Services\CommandRuleMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CommandRuleMatcherTest extends TestCase
{
    private CommandRuleMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new CommandRuleMatcher;
    }

    /**
     * @param  array<string, string>  $rules  prefix => action
     * @return list<CommandRule>
     */
    private static function rules(array $rules): array
    {
        $list = [];
        foreach ($rules as $prefix => $action) {
            $list[] = CommandRule::fromArray(['prefix' => $prefix, 'action' => $action]);
        }

        return $list;
    }

    public function test_longest_prefix_wins(): void
    {
        $rules = self::rules(['git' => 'allow', 'git push' => 'ask']);

        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide('git push --force', $rules));
        $this->assertSame(CommandRuleAction::Allow, $this->matcher->decide('git status', $rules));
    }

    public function test_equal_length_rules_resolve_to_the_most_severe(): void
    {
        $rules = [
            new CommandRule(['npm', 'publish'], CommandRuleAction::Allow),
            new CommandRule(['npm', 'publish'], CommandRuleAction::Block),
            new CommandRule(['npm', 'publish'], CommandRuleAction::Ask),
        ];
        $this->assertSame(CommandRuleAction::Block, $this->matcher->decide('npm publish', $rules));

        $rules = [
            new CommandRule(['npm', 'publish'], CommandRuleAction::Allow),
            new CommandRule(['npm', 'publish'], CommandRuleAction::Ask),
        ];
        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide('npm publish', $rules));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function chainedCommands(): array
    {
        return [
            'and' => ['ls && sudo reboot'],
            'or' => ['ls || sudo reboot'],
            'semicolon' => ['ls; sudo reboot'],
            'pipe' => ['ls | sudo tee x'],
            'background' => ['ls & sudo reboot'],
            'subshell' => ['(cd /tmp && sudo reboot)'],
            'command substitution' => ['echo $(sudo reboot)'],
            'newline' => ["ls\nsudo reboot"],
            'no spaces' => ['ls;sudo reboot'],
        ];
    }

    #[DataProvider('chainedCommands')]
    public function test_chained_commands_are_checked_part_by_part(string $command): void
    {
        $this->assertSame(CommandRuleAction::Block, $this->matcher->decide($command, CommandRule::defaults()));
    }

    public function test_quoted_operators_do_not_split(): void
    {
        $this->assertSame(
            [['echo', "'a; sudo b'"]],
            $this->matcher->segments("echo 'a; sudo b'"),
        );
        $this->assertSame(CommandRuleAction::Allow, $this->matcher->decide('echo "a && sudo b"', CommandRule::defaults()));
    }

    public function test_redirections_are_not_separators_and_their_targets_are_skipped(): void
    {
        $this->assertSame([['make', 'build']], $this->matcher->segments('make build 2>&1 >/dev/null'));
        $this->assertSame([['make']], $this->matcher->segments('make >&2'));
        $this->assertSame([['make']], $this->matcher->segments('make >> log.txt'));
        $this->assertSame([['make']], $this->matcher->segments('make &> log.txt'));
        $this->assertSame([['cat'], ['wc']], $this->matcher->segments('cat < in.txt | wc'));
        $this->assertSame([['git', 'push']], $this->matcher->segments('git push 2>&1'));
        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide('git push 2>&1', CommandRule::defaults()));
        // The redirect target is not a command word.
        $this->assertSame(CommandRuleAction::Allow, $this->matcher->decide('echo hi > sudo', CommandRule::defaults()));
    }

    public function test_unterminated_quote_gives_ask(): void
    {
        $this->assertNull($this->matcher->segments("echo 'oops"));
        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide("echo 'oops", []));
    }

    public function test_empty_command_gives_the_default(): void
    {
        $this->assertSame(CommandRuleAction::Allow, $this->matcher->decide('', CommandRule::defaults()));
        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide('   ', [], [], CommandRuleAction::Ask));
    }

    public function test_unmatched_part_gets_the_default(): void
    {
        $this->assertSame(CommandRuleAction::Allow, $this->matcher->decide('ls -la', CommandRule::defaults()));
        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide('ls -la', CommandRule::defaults(), [], CommandRuleAction::Ask));
    }

    public function test_assignments_wrappers_and_paths_are_normalised(): void
    {
        $rules = CommandRule::defaults();

        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide('FOO=1 BAR="x y" git push', $rules));
        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide('env time nohup git push origin main', $rules));
        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide('/usr/bin/git push', $rules));
        $this->assertSame(CommandRuleAction::Block, $this->matcher->decide('/usr/bin/sudo ls', $rules));
        $this->assertSame(CommandRuleAction::Block, $this->matcher->decide('env sudo ls', $rules));
        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide("'git' \"push\"", $rules));
    }

    public function test_words_match_exactly(): void
    {
        $rules = CommandRule::defaults();

        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide('rm -rf build', $rules));
        $this->assertSame(CommandRuleAction::Allow, $this->matcher->decide('rm -fr build', $rules));
        $this->assertSame(CommandRuleAction::Allow, $this->matcher->decide('git pushx', $rules));
    }

    public function test_command_substitution_is_at_least_ask(): void
    {
        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide('echo `whoami`', []));
        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide('echo "$(whoami)"', []));
        $this->assertSame(CommandRuleAction::Allow, $this->matcher->decide("echo '\$HOME'", []));
    }

    public function test_grants_turn_ask_into_allow_but_never_change_block(): void
    {
        $rules = CommandRule::defaults();

        $this->assertSame(CommandRuleAction::Allow, $this->matcher->decide('git push origin main', $rules, ['git push']));
        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide('git push origin main', $rules, ['git push upstream']));
        $this->assertSame(CommandRuleAction::Block, $this->matcher->decide('sudo ls', $rules, ['sudo']));
        $this->assertSame(CommandRuleAction::Block, $this->matcher->decide('git push && sudo ls', $rules, ['git push', 'sudo']));
        // Invalid grants are ignored.
        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide('git push', $rules, ['git push; ls']));
    }

    public function test_overall_result_is_the_most_severe_part(): void
    {
        $rules = CommandRule::defaults();

        $this->assertSame(CommandRuleAction::Ask, $this->matcher->decide('ls && git push', $rules));
        $this->assertSame(CommandRuleAction::Block, $this->matcher->decide('git push; sudo ls; ls', $rules));
    }
}
