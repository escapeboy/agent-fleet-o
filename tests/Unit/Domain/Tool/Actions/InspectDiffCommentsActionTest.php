<?php

namespace Tests\Unit\Domain\Tool\Actions;

use App\Domain\Tool\Actions\InspectDiffCommentsAction;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Mockery;
use Tests\TestCase;

class InspectDiffCommentsActionTest extends TestCase
{
    private function makeResponse(string $content): AiResponseDTO
    {
        return new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 5, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 10,
        );
    }

    private function resolverReturning(): ProviderResolver
    {
        $resolver = Mockery::mock(ProviderResolver::class);
        $resolver->shouldReceive('resolve')
            ->andReturn(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5']);

        return $resolver;
    }

    // --- diff parsing -------------------------------------------------------

    public function test_extracts_added_comment_with_file_and_line(): void
    {
        $diff = <<<'DIFF'
        diff --git a/src/a.php b/src/a.php
        --- a/src/a.php
        +++ b/src/a.php
        @@ -1,2 +1,4 @@
         <?php
        +// a decorative comment
        +$x = 1;
         echo $x;
        DIFF;

        $action = new InspectDiffCommentsAction(Mockery::mock(AiGatewayInterface::class), $this->resolverReturning());
        $found = $action->extractAddedComments($diff);

        $this->assertCount(1, $found);
        $this->assertSame('src/a.php', $found[0]['file']);
        $this->assertSame(2, $found[0]['line']);
        $this->assertSame('// a decorative comment', $found[0]['comment']);
    }

    public function test_ignores_headers_removed_lines_and_plain_code(): void
    {
        $diff = <<<'DIFF'
        diff --git a/src/b.php b/src/b.php
        --- a/src/b.php
        +++ b/src/b.php
        @@ -1,3 +1,3 @@
         <?php
        -// old removed comment
        +$y = 2;
         echo $y;
        DIFF;

        $action = new InspectDiffCommentsAction(Mockery::mock(AiGatewayInterface::class), $this->resolverReturning());

        // The +++ header and the removed comment must not count; the added code line is not a comment.
        $this->assertSame([], $action->extractAddedComments($diff));
    }

    public function test_comment_detection_is_language_aware(): void
    {
        $diff = <<<'DIFF'
        diff --git a/run.sh b/run.sh
        --- a/run.sh
        +++ b/run.sh
        @@ -1 +1,2 @@
         echo hi
        +# shell comment
        diff --git a/style.css b/style.css
        --- a/style.css
        +++ b/style.css
        @@ -1 +1,2 @@
         body{}
        +#main { color: red; }
        DIFF;

        $action = new InspectDiffCommentsAction(Mockery::mock(AiGatewayInterface::class), $this->resolverReturning());
        $found = $action->extractAddedComments($diff);

        // '#' is a comment in shell but an id selector in CSS.
        $this->assertCount(1, $found);
        $this->assertSame('run.sh', $found[0]['file']);
    }

    public function test_line_numbers_track_the_new_file_hunk_start(): void
    {
        $diff = <<<'DIFF'
        diff --git a/src/c.php b/src/c.php
        --- a/src/c.php
        +++ b/src/c.php
        @@ -1,2 +10,3 @@
         context line
        +// comment at ten
         trailing
        DIFF;

        $action = new InspectDiffCommentsAction(Mockery::mock(AiGatewayInterface::class), $this->resolverReturning());
        $found = $action->extractAddedComments($diff);

        $this->assertCount(1, $found);
        $this->assertSame(11, $found[0]['line']);
    }

    // --- judge behaviour ----------------------------------------------------

    private function diffWithOneComment(): string
    {
        return <<<'DIFF'
        diff --git a/src/a.php b/src/a.php
        --- a/src/a.php
        +++ b/src/a.php
        @@ -1,2 +1,3 @@
         <?php
        +// a decorative comment
         echo 1;
        DIFF;
    }

    public function test_flags_low_value_comment(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()->andReturn($this->makeResponse(
            '{"flagged":[{"file":"src/a.php","line":2,"comment":"// a decorative comment","reason":"restates code"}],"summary":"1 flagged"}',
        ));

        $action = new InspectDiffCommentsAction($gateway, $this->resolverReturning());
        $result = $action->execute($this->diffWithOneComment());

        $this->assertTrue($result->judged);
        $this->assertTrue($result->hasFindings());
        $this->assertCount(1, $result->flagged);
        $this->assertSame('restates code', $result->flagged[0]['reason']);
    }

    public function test_passes_justified_comment(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()->andReturn($this->makeResponse(
            '{"flagged":[],"summary":"comment records a real WHY"}',
        ));

        $action = new InspectDiffCommentsAction($gateway, $this->resolverReturning());
        $result = $action->execute($this->diffWithOneComment());

        $this->assertTrue($result->judged);
        $this->assertFalse($result->hasFindings());
    }

    public function test_skips_judge_when_no_comments(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldNotReceive('complete');

        $resolver = Mockery::mock(ProviderResolver::class);
        $resolver->shouldNotReceive('resolve');

        $diff = <<<'DIFF'
        diff --git a/src/d.php b/src/d.php
        --- a/src/d.php
        +++ b/src/d.php
        @@ -1,1 +1,2 @@
         <?php
        +$z = 3;
        DIFF;

        $action = new InspectDiffCommentsAction($gateway, $resolver);
        $result = $action->execute($diff);

        $this->assertTrue($result->judged);
        $this->assertFalse($result->hasFindings());
        $this->assertSame(0, $result->addedComments);
    }

    public function test_fails_open_when_gateway_throws(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()->andThrow(new \RuntimeException('API down'));

        $action = new InspectDiffCommentsAction($gateway, $this->resolverReturning());
        $result = $action->execute($this->diffWithOneComment());

        $this->assertFalse($result->judged);
        $this->assertFalse($result->hasFindings());
    }

    public function test_parses_markdown_fenced_json(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()->andReturn($this->makeResponse(
            "Here you go:\n```json\n{\"flagged\":[{\"file\":\"src/a.php\",\"line\":2,\"comment\":\"// a decorative comment\",\"reason\":\"obvious\"}],\"summary\":\"x\"}\n```",
        ));

        $action = new InspectDiffCommentsAction($gateway, $this->resolverReturning());
        $result = $action->execute($this->diffWithOneComment());

        $this->assertTrue($result->judged);
        $this->assertCount(1, $result->flagged);
    }
}
