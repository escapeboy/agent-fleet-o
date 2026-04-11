<?php

namespace Tests\Unit\Domain\Assistant;

use App\Domain\Assistant\Services\AssistantUiArtifactParser;
use Tests\TestCase;

class AssistantUiArtifactParserTest extends TestCase
{
    private AssistantUiArtifactParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AssistantUiArtifactParser;
    }

    public function test_returns_full_text_when_no_delimiter(): void
    {
        $result = $this->parser->parse('Hello there.', []);
        $this->assertSame('Hello there.', $result['text']);
        $this->assertSame([], $result['artifacts']);
    }

    public function test_extracts_single_valid_artifact(): void
    {
        $content = <<<'TXT'
Here is your metric.

<<<FLEETQ_ARTIFACTS>>>
{"artifacts":[{"type":"metric_card","label":"Spend","value":1234.5,"unit":"EUR"}]}
<<<END>>>
TXT;

        $result = $this->parser->parse($content, []);
        $this->assertSame('Here is your metric.', $result['text']);
        $this->assertCount(1, $result['artifacts']);
        $this->assertSame('metric_card', $result['artifacts'][0]->type());
    }

    public function test_strips_malformed_tail_when_end_delimiter_missing(): void
    {
        $content = "Real answer.\n\n<<<FLEETQ_ARTIFACTS>>>\n{oops";
        $result = $this->parser->parse($content, []);
        $this->assertSame('Real answer.', $result['text']);
        $this->assertSame([], $result['artifacts']);
    }

    public function test_returns_empty_artifacts_on_invalid_json(): void
    {
        $content = "Text.\n\n<<<FLEETQ_ARTIFACTS>>>\nnot-json\n<<<END>>>";
        $result = $this->parser->parse($content, []);
        $this->assertSame('Text.', $result['text']);
        $this->assertSame([], $result['artifacts']);
    }

    public function test_drops_individual_bad_artifacts_but_keeps_good_ones(): void
    {
        $content = <<<'TXT'
Two things.

<<<FLEETQ_ARTIFACTS>>>
{"artifacts":[
  {"type":"iframe","src":"evil"},
  {"type":"metric_card","label":"OK","value":1}
]}
<<<END>>>
TXT;

        $result = $this->parser->parse($content, []);
        $this->assertCount(1, $result['artifacts']);
        $this->assertSame('metric_card', $result['artifacts'][0]->type());
    }

    public function test_respects_max_artifacts_per_message_cap(): void
    {
        $artifacts = [];
        for ($i = 0; $i < 10; $i++) {
            $artifacts[] = ['type' => 'metric_card', 'label' => "m{$i}", 'value' => $i];
        }
        $content = "Many.\n\n<<<FLEETQ_ARTIFACTS>>>\n".json_encode(['artifacts' => $artifacts])."\n<<<END>>>";

        $result = $this->parser->parse($content, []);
        $this->assertLessThanOrEqual(3, count($result['artifacts']));
    }

    public function test_data_table_requires_real_tool_call_in_turn(): void
    {
        $content = <<<'TXT'
Table coming.

<<<FLEETQ_ARTIFACTS>>>
{"artifacts":[{"type":"data_table","source_tool":"experiment_list","columns":[{"key":"id","label":"ID"}],"rows":[{"id":"abc"}]}]}
<<<END>>>
TXT;

        // Without the tool call → rejected.
        $empty = $this->parser->parse($content, []);
        $this->assertCount(0, $empty['artifacts']);

        // With it → accepted.
        $good = $this->parser->parse($content, [['name' => 'experiment_list']]);
        $this->assertCount(1, $good['artifacts']);
    }

    public function test_artifacts_key_missing_returns_empty(): void
    {
        $content = "x\n\n<<<FLEETQ_ARTIFACTS>>>\n{\"unrelated\":true}\n<<<END>>>";
        $result = $this->parser->parse($content, []);
        $this->assertCount(0, $result['artifacts']);
    }

    public function test_preserves_text_whitespace_inside_body_but_trims_tail(): void
    {
        $content = "Line one.\n\nLine two.\n\n\n<<<FLEETQ_ARTIFACTS>>>\n{\"artifacts\":[]}\n<<<END>>>";
        $result = $this->parser->parse($content, []);
        $this->assertStringContainsString("Line one.\n\nLine two.", $result['text']);
        $this->assertStringNotContainsString('<<<', $result['text']);
    }
}
