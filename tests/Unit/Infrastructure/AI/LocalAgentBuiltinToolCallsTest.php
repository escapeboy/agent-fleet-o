<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Gateways\LocalAgentOutputParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LocalAgentBuiltinToolCallsTest extends TestCase
{
    private function assistant(array $blocks): string
    {
        return json_encode(['type' => 'assistant', 'message' => ['content' => $blocks]]);
    }

    private function tool(string $name): array
    {
        return ['type' => 'tool_use', 'id' => 'x', 'name' => $name, 'input' => ['secret' => 'hunter2']];
    }

    public function test_mixed_mcp_and_builtin_in_order_with_duplicates(): void
    {
        $out = implode("\n", [
            $this->assistant([['type' => 'text', 'text' => 'hi'], $this->tool('Bash'), $this->tool('mcp__fleetq__agent_list')]),
            json_encode(['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'content' => 'x']]]]),
            $this->assistant([$this->tool('Read')]),
            $this->assistant([$this->tool('Bash')]),
            json_encode(['type' => 'result', 'result' => 'done']),
        ]);

        $r = LocalAgentOutputParser::builtinToolCalls($out);

        $this->assertSame(['Bash', 'Read', 'Bash'], $r['names']);
        $this->assertFalse($r['truncated']);
    }

    public function test_only_mcp_calls_yield_empty_list(): void
    {
        $r = LocalAgentOutputParser::builtinToolCalls($this->assistant([$this->tool('mcp__a__b')]));

        $this->assertSame([], $r['names']);
    }

    public function test_malformed_lines_and_odd_shapes_are_skipped(): void
    {
        $out = implode("\n", [
            '{not json',
            '',
            '"just a string"',
            json_encode(['type' => 'assistant', 'message' => ['content' => 'plain']]),
            json_encode(['type' => 'assistant']),
            $this->assistant(['bad', ['type' => 'tool_use'], ['type' => 'tool_use', 'name' => 7], $this->tool('Glob')]),
        ]);

        $this->assertSame(['Glob'], LocalAgentOutputParser::builtinToolCalls($out)['names']);
    }

    public function test_empty_input(): void
    {
        $this->assertSame(
            ['names' => [], 'truncated' => false, 'bash_programs' => [], 'bash_truncated' => false],
            LocalAgentOutputParser::builtinToolCalls(''),
        );
    }

    public function test_cap_at_500_sets_truncated(): void
    {
        $blocks = array_fill(0, 501, $this->tool('Bash'));
        $r = LocalAgentOutputParser::builtinToolCalls($this->assistant($blocks));

        $this->assertCount(500, $r['names']);
        $this->assertTrue($r['truncated']);
    }

    public function test_exactly_500_is_not_truncated(): void
    {
        $r = LocalAgentOutputParser::builtinToolCalls($this->assistant(array_fill(0, 500, $this->tool('Bash'))));

        $this->assertCount(500, $r['names']);
        $this->assertFalse($r['truncated']);
    }

    private function bash(mixed $input): string
    {
        return $this->assistant([['type' => 'tool_use', 'id' => 'x', 'name' => 'Bash', 'input' => $input]]);
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function programCases(): array
    {
        return [
            'plain' => ['ls -la /tmp', 'ls'],
            'env prefix' => ['FOO=bar BAZ="a b" make test', 'make'],
            'sudo' => ['sudo systemctl restart x', 'systemctl'],
            'sudo then program' => ['sudo env A=1 time nohup python3 run.py', 'python3'],
            'absolute path' => ['/usr/bin/curl -s https://x.test', 'curl'],
            'quoted program' => ['"/opt/bin/git" status', 'git'],
            'quoted with whitespace' => ['"/opt/my tool/bin/git" status', '?'],
            'single quoted' => ["'npm' install", 'npm'],
            'pipe' => ['cat file | grep x', 'cat'],
            'and' => ['cd /tmp&&rm -rf x', 'cd'],
            'semicolon' => ['echo hi; ls', 'echo'],
            'empty' => ['', '?'],
            'whitespace only' => ["  \n ", '?'],
            'only assignment' => ['FOO=bar', '?'],
            'starts with operator' => ['| cat', '?'],
            'subshell' => ['(cd x && ls)', '?'],
            'command substitution' => ['$(whoami) arg', '?'],
            'unterminated quote' => ['"curl -H token=abc', '?'],
            'illegal chars rejected' => ['py!th@on3 x', '?'],
            'env flag' => ["env -S'curl -H X-Token:sk-abc123' x", '?'],
            'env -i' => ['env -i ls', '?'],
            'sudo -u' => ['sudo -u root ls', '?'],
            'sudo --user=' => ['sudo --user=root ls', '?'],
            'quoted secret with spaces' => ['"curl -H Bearer sk-abc123" x', '?'],
            'plus-equals assignment' => ['A+=sk-secret123 cmd', 'cmd'],
            'indexed assignment' => ['A[0]=sk-secret123 cmd', 'cmd'],
            'secret as name with equals' => ['sk-abc123=1 cmd', '?'],
            'url' => ['https://x/?token=sk-abc123', '?'],
            'github token path' => ['./ghp_'.str_repeat('a1B2', 9).' x', '?'],
            'sk prefix' => ['sk-abcdef x', '?'],
            'aws prefix' => ['AKIAABCDEFGH x', '?'],
            'jwt prefix' => ['eyJhbGciOi x', '?'],
            'long digit run' => ['tool12345678 x', '?'],
            'long mixed run' => ['abc123def456ghi789jkl012mno x', '?'],
            'env var as program' => ['"$TOKEN" x', '?'],
            'cat' => ['cat f', 'cat'],
            'bash' => ['bash -c x', 'bash'],
            'long name rejected' => [str_repeat('a', 33).' x', '?'],
            'max length ok' => [str_repeat('a', 32).' x', str_repeat('a', 32)],
            'non-string' => [['curl'], '?'],
            'null' => [null, '?'],
            'int' => [42, '?'],
            'trailing slash' => ['/usr/bin/ x', '?'],
            'upper-case key prefix' => ['SK-abc123 x', '?'],
            'mixed-case key prefix' => ['Sk-proj-abc x', '?'],
            'aws prefix lower-case a' => ['aKIAabcdefgh x', '?'],
            'gitlab token' => ['glpat-abcdefghijklmnopqrst x', '?'],
            'stripe restricted key' => ['rk_live_abc x', '?'],
            'google api key' => ['AIzaSyAbc x', '?'],
        ];
    }

    #[DataProvider('programCases')]
    public function test_bash_program_extraction(mixed $command, string $expected): void
    {
        $this->assertSame($expected, LocalAgentOutputParser::bashProgram($command));
    }

    public function test_bash_programs_one_per_call_in_order_exact_name_only(): void
    {
        $out = implode("\n", [
            $this->bash(['command' => 'ls']),
            $this->assistant([['type' => 'tool_use', 'name' => 'bash', 'input' => ['command' => 'nope']]]),
            $this->assistant([['type' => 'tool_use', 'name' => 'BashOutput', 'input' => ['command' => 'nope']]]),
            $this->bash(['command' => 'git push']),
            $this->bash('not-an-array'),
            $this->bash(['command' => 123]),
            $this->bash([]),
        ]);

        $r = LocalAgentOutputParser::builtinToolCalls($out);

        $this->assertSame(['ls', 'git', '?', '?', '?'], $r['bash_programs']);
        $this->assertFalse($r['bash_truncated']);
        $this->assertSame(['Bash', 'bash', 'BashOutput', 'Bash', 'Bash', 'Bash', 'Bash'], $r['names']);
    }

    public function test_bash_programs_never_contain_arguments_or_secrets(): void
    {
        $token = 'sk-live-FAKE_TOKEN_9f8e7d';
        $out = implode("\n", [
            $this->bash(['command' => "API_KEY=$token curl -H 'Authorization: Bearer $token' https://x.test/$token | tee $token"]),
            $this->bash(['command' => "echo $token && sudo $token"]),
            $this->bash(['command' => "FOO=$token"]),
        ]);

        $r = LocalAgentOutputParser::builtinToolCalls($out);

        $this->assertSame(['curl', 'echo', '?'], $r['bash_programs']);
        $this->assertStringNotContainsString('FAKE_TOKEN', json_encode($r));
        $this->assertStringNotContainsString($token, json_encode($r));
        $this->assertStringNotContainsString('sk-', json_encode($r));
    }

    public function test_bash_cap_is_independent_of_names_cap(): void
    {
        $blocks = array_merge(
            array_fill(0, 500, ['type' => 'tool_use', 'name' => 'Read', 'input' => []]),
            [['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'ls']]],
        );
        $r = LocalAgentOutputParser::builtinToolCalls($this->assistant($blocks));

        $this->assertCount(500, $r['names']);
        $this->assertTrue($r['truncated']);
        $this->assertSame(['ls'], $r['bash_programs']);
        $this->assertFalse($r['bash_truncated']);

        $r = LocalAgentOutputParser::builtinToolCalls($this->assistant(array_fill(0, 501, ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'ls']])));
        $this->assertCount(500, $r['bash_programs']);
        $this->assertTrue($r['bash_truncated']);
    }

    public function test_reviewer_secret_cases_store_nothing_secret_through_the_parser(): void
    {
        $ghp = 'ghp_'.str_repeat('a1B2', 9);
        $cmds = [
            "env -S'curl -H X-Token:sk-abc123'",
            '"curl -H Bearer sk-abc123" x',
            'A+=sk-secret123 cmd',
            'A[0]=sk-secret123 cmd',
            'sk-abc123=1 cmd',
            'sudo --user=root ls',
            'https://x/?token=sk-abc123',
            "./$ghp",
        ];
        $out = implode("\n", array_map(fn ($c) => $this->bash(['command' => $c]), $cmds));

        $r = LocalAgentOutputParser::builtinToolCalls($out);

        $this->assertSame(['?', '?', 'cmd', 'cmd', '?', '?', '?', '?'], $r['bash_programs']);
        $stored = json_encode($r['bash_programs']);
        $this->assertStringNotContainsString('sk-', $stored);
        $this->assertStringNotContainsString('ghp', $stored);
    }
}
