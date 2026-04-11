<?php

namespace Tests\Unit\Domain\Assistant\Artifacts;

use App\Domain\Assistant\Artifacts\ArtifactFactory;
use App\Domain\Assistant\Artifacts\ChoiceCardsArtifact;
use App\Domain\Assistant\Artifacts\CodeDiffArtifact;
use App\Domain\Assistant\Artifacts\ConfirmationDialogArtifact;
use App\Domain\Assistant\Artifacts\DataTableArtifact;
use App\Domain\Assistant\Artifacts\FormArtifact;
use App\Domain\Assistant\Artifacts\LinkListArtifact;
use App\Domain\Assistant\Artifacts\MetricCardArtifact;
use App\Domain\Assistant\Artifacts\ProgressTrackerArtifact;
use Tests\TestCase;

class ArtifactFactoryTest extends TestCase
{
    // ─── DATA TABLE ──────────────────────────────────────────────────────

    public function test_data_table_requires_source_tool_to_have_run(): void
    {
        $raw = [
            'type' => 'data_table',
            'source_tool' => 'experiment_list',
            'columns' => [['key' => 'id', 'label' => 'ID']],
            'rows' => [['id' => 'abc']],
        ];
        $this->assertNull(ArtifactFactory::build($raw, []));
        $this->assertInstanceOf(
            DataTableArtifact::class,
            ArtifactFactory::build($raw, [['name' => 'experiment_list']]),
        );
    }

    public function test_data_table_caps_rows_and_marks_truncated(): void
    {
        $rows = [];
        for ($i = 0; $i < 80; $i++) {
            $rows[] = ['id' => (string) $i];
        }
        $raw = [
            'type' => 'data_table',
            'source_tool' => 'experiment_list',
            'columns' => [['key' => 'id', 'label' => 'ID']],
            'rows' => $rows,
        ];

        $artifact = ArtifactFactory::build($raw, [['name' => 'experiment_list']]);
        $this->assertNotNull($artifact);
        $payload = $artifact->toPayload();
        $this->assertCount(50, $payload['rows']);
        $this->assertTrue($payload['truncated']);
    }

    public function test_data_table_strips_xss_from_cells(): void
    {
        $raw = [
            'type' => 'data_table',
            'source_tool' => 'experiment_list',
            'columns' => [['key' => 'name', 'label' => 'Name']],
            'rows' => [['name' => '<script>alert(1)</script>Alice']],
        ];
        $artifact = ArtifactFactory::build($raw, [['name' => 'experiment_list']]);
        $cell = $artifact->toPayload()['rows'][0]['name'];
        // strip_tags removes the <script> element itself but leaves inner text.
        // That's fine — Blade auto-escapes on render, so the remaining text is
        // displayed as literal characters, not executed. The invariant we care
        // about is: no raw tags, no angle brackets.
        $this->assertStringNotContainsString('<script>', $cell);
        $this->assertStringNotContainsString('<', $cell);
        $this->assertStringContainsString('Alice', $cell);
    }

    // ─── CHART ───────────────────────────────────────────────────────────

    public function test_chart_rejects_unknown_chart_type(): void
    {
        $raw = [
            'type' => 'chart',
            'source_tool' => 'metric_list',
            'chart_type' => 'radar',
            'data_points' => [['label' => 'jan', 'value' => 1]],
        ];
        $this->assertNull(ArtifactFactory::build($raw, [['name' => 'metric_list']]));
    }

    public function test_chart_caps_data_points(): void
    {
        $points = [];
        for ($i = 0; $i < 200; $i++) {
            $points[] = ['label' => "d{$i}", 'value' => $i];
        }
        $raw = [
            'type' => 'chart',
            'source_tool' => 'metric_list',
            'chart_type' => 'line',
            'data_points' => $points,
        ];
        $artifact = ArtifactFactory::build($raw, [['name' => 'metric_list']]);
        $this->assertCount(100, $artifact->toPayload()['data_points']);
    }

    // ─── CHOICE CARDS ────────────────────────────────────────────────────

    public function test_choice_cards_requires_minimum_two_valid_options(): void
    {
        $raw = [
            'type' => 'choice_cards',
            'question' => 'Pick one',
            'options' => [
                ['label' => 'A', 'value' => 'a'],
            ],
        ];
        $this->assertNull(ArtifactFactory::build($raw, []));
    }

    public function test_choice_cards_accepts_dismiss_action_by_default(): void
    {
        $raw = [
            'type' => 'choice_cards',
            'question' => 'Pick one',
            'options' => [
                ['label' => 'A', 'value' => 'a'],
                ['label' => 'B', 'value' => 'b'],
            ],
        ];
        $artifact = ArtifactFactory::build($raw, []);
        $this->assertInstanceOf(ChoiceCardsArtifact::class, $artifact);
        $this->assertSame('dismiss', $artifact->toPayload()['options'][0]['action']['type']);
    }

    public function test_choice_cards_rejects_javascript_navigate_url(): void
    {
        $raw = [
            'type' => 'choice_cards',
            'question' => 'Go',
            'options' => [
                ['label' => 'Safe', 'value' => 's', 'action' => ['type' => 'navigate', 'url' => 'https://fleetq.net']],
                ['label' => 'Evil', 'value' => 'e', 'action' => ['type' => 'navigate', 'url' => 'javascript:alert(1)']],
            ],
        ];
        $artifact = ArtifactFactory::build($raw, []);
        // Evil option dropped → only 1 left → under the min → null.
        $this->assertNull($artifact);
    }

    // ─── FORM ────────────────────────────────────────────────────────────

    public function test_form_degrades_select_without_options_to_textarea(): void
    {
        $raw = [
            'type' => 'form',
            'title' => 'Quick',
            'fields' => [['name' => 'x', 'label' => 'X', 'type' => 'select', 'options' => []]],
        ];
        $artifact = ArtifactFactory::build($raw, []);
        $this->assertInstanceOf(FormArtifact::class, $artifact);
        $this->assertSame('textarea', $artifact->toPayload()['fields'][0]['type']);
    }

    public function test_form_drops_unknown_field_type(): void
    {
        $raw = [
            'type' => 'form',
            'title' => 'Quick',
            'fields' => [
                ['name' => 'x', 'label' => 'X', 'type' => 'iframe'],
                ['name' => 'y', 'label' => 'Y', 'type' => 'text'],
            ],
        ];
        $artifact = ArtifactFactory::build($raw, []);
        $this->assertCount(1, $artifact->toPayload()['fields']);
        $this->assertSame('y', $artifact->toPayload()['fields'][0]['name']);
    }

    // ─── LINK LIST ───────────────────────────────────────────────────────

    public function test_link_list_drops_unsafe_urls(): void
    {
        $raw = [
            'type' => 'link_list',
            'items' => [
                ['label' => 'Safe', 'url' => 'https://fleetq.net'],
                ['label' => 'Evil', 'url' => 'javascript:alert(1)'],
                ['label' => 'Data', 'url' => 'data:text/html,<script>x</script>'],
            ],
        ];
        $artifact = ArtifactFactory::build($raw, []);
        $this->assertInstanceOf(LinkListArtifact::class, $artifact);
        $this->assertCount(1, $artifact->toPayload()['items']);
    }

    public function test_link_list_rejects_empty_items(): void
    {
        $raw = ['type' => 'link_list', 'items' => []];
        $this->assertNull(ArtifactFactory::build($raw, []));
    }

    // ─── CODE DIFF ───────────────────────────────────────────────────────

    public function test_code_diff_rejects_unknown_language(): void
    {
        $raw = [
            'type' => 'code_diff',
            'title' => 'Fix',
            'language' => 'malbolge',
            'before' => 'x',
            'after' => 'y',
        ];
        $this->assertNull(ArtifactFactory::build($raw, []));
    }

    public function test_code_diff_rejects_traversal_in_path(): void
    {
        $raw = [
            'type' => 'code_diff',
            'title' => 'Fix',
            'language' => 'php',
            'file_path' => '../../../etc/passwd',
            'before' => 'x',
            'after' => 'y',
        ];
        $this->assertNull(ArtifactFactory::build($raw, []));
    }

    public function test_code_diff_rejects_too_large_total(): void
    {
        $raw = [
            'type' => 'code_diff',
            'title' => 'Fix',
            'language' => 'php',
            'before' => str_repeat('a', 3000),
            'after' => str_repeat('b', 3000),
        ];
        $this->assertNull(ArtifactFactory::build($raw, []));
    }

    public function test_code_diff_preserves_html_inside_code_blocks(): void
    {
        $raw = [
            'type' => 'code_diff',
            'title' => 'Template',
            'language' => 'blade',
            'before' => '<div>old</div>',
            'after' => '<div>new</div>',
        ];
        $artifact = ArtifactFactory::build($raw, []);
        $this->assertInstanceOf(CodeDiffArtifact::class, $artifact);
        // Intentionally preserved: Blade auto-escapes at render.
        $this->assertStringContainsString('<div>', $artifact->toPayload()['after']);
    }

    // ─── CONFIRMATION DIALOG ─────────────────────────────────────────────

    public function test_confirmation_dialog_requires_invoke_tool_action(): void
    {
        $raw = [
            'type' => 'confirmation_dialog',
            'title' => 'Delete?',
            'body' => 'Really?',
            'on_confirm' => ['type' => 'navigate', 'url' => 'https://fleetq.net'],
        ];
        $this->assertNull(ArtifactFactory::build($raw, []));
    }

    public function test_confirmation_dialog_happy_path(): void
    {
        $raw = [
            'type' => 'confirmation_dialog',
            'title' => 'Kill experiment?',
            'body' => 'This stops the running experiment.',
            'confirm_label' => 'Yes, kill',
            'cancel_label' => 'Cancel',
            'destructive' => true,
            'on_confirm' => [
                'type' => 'invoke_tool',
                'tool_name' => 'experiment_kill',
                'parameters' => ['id' => 'abc'],
            ],
        ];
        $artifact = ArtifactFactory::build($raw, []);
        $this->assertInstanceOf(ConfirmationDialogArtifact::class, $artifact);
        $this->assertTrue($artifact->toPayload()['destructive']);
    }

    // ─── METRIC CARD ─────────────────────────────────────────────────────

    public function test_metric_card_rejects_non_numeric_value(): void
    {
        $this->assertNull(ArtifactFactory::build([
            'type' => 'metric_card',
            'label' => 'Spend',
            'value' => 'lots',
        ], []));
    }

    public function test_metric_card_allows_null_source_tool_for_literal_values(): void
    {
        $artifact = ArtifactFactory::build([
            'type' => 'metric_card',
            'label' => 'Rate',
            'value' => 0.25,
            'unit' => '%',
        ], []);
        $this->assertInstanceOf(MetricCardArtifact::class, $artifact);
    }

    public function test_metric_card_rejects_source_tool_that_did_not_run(): void
    {
        $this->assertNull(ArtifactFactory::build([
            'type' => 'metric_card',
            'label' => 'Spend',
            'value' => 100,
            'source_tool' => 'budget_summary',
        ], []));
    }

    public function test_metric_card_drops_unknown_trend(): void
    {
        $artifact = ArtifactFactory::build([
            'type' => 'metric_card',
            'label' => 'x',
            'value' => 1,
            'trend' => 'sideways',
        ], []);
        $this->assertNull($artifact->toPayload()['trend']);
    }

    // ─── PROGRESS TRACKER ────────────────────────────────────────────────

    public function test_progress_tracker_clamps_progress(): void
    {
        $artifact = ArtifactFactory::build([
            'type' => 'progress_tracker',
            'label' => 'Running',
            'progress' => 150,
        ], []);
        $this->assertInstanceOf(ProgressTrackerArtifact::class, $artifact);
        $this->assertSame(100, $artifact->toPayload()['progress']);
    }

    public function test_progress_tracker_defaults_unknown_state_to_running(): void
    {
        $artifact = ArtifactFactory::build([
            'type' => 'progress_tracker',
            'label' => 'x',
            'progress' => 50,
            'state' => 'whatever',
        ], []);
        $this->assertSame('running', $artifact->toPayload()['state']);
    }

    // ─── FACTORY GLOBAL ──────────────────────────────────────────────────

    public function test_factory_rejects_unknown_type(): void
    {
        $this->assertNull(ArtifactFactory::build(['type' => 'stl_viewer'], []));
    }

    public function test_build_many_caps_artifact_count(): void
    {
        $raws = [];
        for ($i = 0; $i < 10; $i++) {
            $raws[] = [
                'type' => 'metric_card',
                'label' => "m{$i}",
                'value' => $i,
            ];
        }
        $built = ArtifactFactory::buildMany($raws, []);
        $this->assertLessThanOrEqual(ArtifactFactory::MAX_ARTIFACTS_PER_MESSAGE, count($built));
    }

    public function test_factory_silently_swallows_factory_exceptions(): void
    {
        // Pass a type whose payload would cause a method call on null if not guarded.
        $artifact = ArtifactFactory::build(['type' => 'data_table'], []);
        $this->assertNull($artifact);
    }
}
