<?php

namespace Tests\Feature\Domain\Audit;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuditLogExportTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['terms.current_version' => 0]);

        $this->team = Team::factory()->create();
        $this->user = User::factory()->create(['current_team_id' => $this->team->id, 'name' => 'Alice Auditor']);
        $this->user->teams()->attach($this->team);
    }

    private function entry(string $event, string $createdAt, ?string $teamId = null, ?string $userId = null, array $properties = []): AuditEntry
    {
        return AuditEntry::withoutGlobalScopes()->create([
            'team_id' => $teamId ?? $this->team->id,
            'user_id' => $userId,
            'event' => $event,
            'properties' => $properties,
            'created_at' => Carbon::parse($createdAt),
        ]);
    }

    private function exportEntries(): Collection
    {
        return AuditEntry::withoutGlobalScopes()->where('event', 'audit.exported')->get();
    }

    public function test_csv_export_streams_header_and_rows(): void
    {
        $this->entry('experiment.created', '2026-03-01 10:00:00', userId: $this->user->id, properties: ['k' => 'v']);
        $this->entry('agent.updated', '2026-03-02 10:00:00');

        $response = $this->actingAs($this->user)->get(route('audit.export', ['format' => 'csv']));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertMatchesRegularExpression('/audit-log-[0-9-]+\.csv/', $disposition);

        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));
        $header = str_getcsv($lines[0], ',', '"', '');
        $this->assertSame(['id', 'created_at', 'event', 'user_id', 'user_name', 'subject_type', 'subject_id', 'triggered_by', 'ip_address', 'ocsf_class_uid', 'ocsf_severity_id', 'properties'], $header);

        $body = implode("\n", array_slice($lines, 1));
        $this->assertStringContainsString('experiment.created', $body);
        $this->assertStringContainsString('agent.updated', $body);
        $this->assertStringContainsString('Alice Auditor', $body);
        // Newest first.
        $this->assertLessThan(strpos($body, 'experiment.created'), strpos($body, 'agent.updated'));
    }

    public function test_jsonl_export_emits_one_ocsf_record_per_line(): void
    {
        $this->entry('experiment.created', '2026-03-01 10:00:00', userId: $this->user->id);
        $this->entry('budget.exceeded', '2026-03-02 10:00:00');

        $response = $this->actingAs($this->user)->get(route('audit.export', ['format' => 'jsonl']));

        $response->assertOk();
        $this->assertStringStartsWith('application/x-ndjson', (string) $response->headers->get('Content-Type'));

        // The export writes its own audit.exported entry before streaming; skip it here.
        $lines = array_values(array_filter(
            explode("\n", $response->streamedContent()),
            fn ($line) => $line !== '' && ! str_contains($line, '"activity_name":"audit.exported"'),
        ));
        $this->assertCount(2, $lines);

        $events = [];
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            $this->assertIsArray($record);
            foreach (['class_uid', 'category_uid', 'severity_id', 'time', 'activity_name'] as $key) {
                $this->assertArrayHasKey($key, $record);
            }
            $this->assertSame('FleetQ', $record['metadata']['product']['name']);
            $events[] = $record['activity_name'];
        }

        $this->assertSame(['budget.exceeded', 'experiment.created'], $events);
    }

    public function test_export_applies_search_event_and_date_filters(): void
    {
        $this->entry('experiment.created', '2026-03-01 10:00:00');
        $this->entry('experiment.killed', '2026-03-05 10:00:00');
        $this->entry('experiment.paused', '2026-03-10 10:00:00');
        $this->entry('agent.created', '2026-03-05 12:00:00');

        $this->actingAs($this->user);

        $body = $this->get(route('audit.export', ['format' => 'csv', 'search' => 'killed']))->streamedContent();
        $this->assertStringContainsString('experiment.killed', $body);
        $this->assertStringNotContainsString('experiment.created', $body);
        $this->assertStringNotContainsString('agent.created', $body);

        $body = $this->get(route('audit.export', ['format' => 'csv', 'event' => 'agent']))->streamedContent();
        $this->assertStringContainsString('agent.created', $body);
        $this->assertStringNotContainsString('experiment.', $body);

        $body = $this->get(route('audit.export', ['format' => 'csv', 'from' => '2026-03-05', 'to' => '2026-03-05']))->streamedContent();
        $this->assertStringContainsString('experiment.killed', $body);
        $this->assertStringContainsString('agent.created', $body);
        $this->assertStringNotContainsString('experiment.created', $body);
        $this->assertStringNotContainsString('experiment.paused', $body);
    }

    public function test_export_excludes_other_teams_entries(): void
    {
        $other = Team::factory()->create();
        $this->entry('experiment.mine', '2026-03-01 10:00:00');
        $this->entry('experiment.theirs', '2026-03-01 10:00:00', teamId: $other->id);

        $body = $this->actingAs($this->user)
            ->get(route('audit.export', ['format' => 'jsonl']))
            ->streamedContent();

        $this->assertStringContainsString('experiment.mine', $body);
        $this->assertStringNotContainsString('experiment.theirs', $body);
    }

    public function test_export_forbidden_when_manage_team_denied(): void
    {
        $this->get(route('audit.export', ['format' => 'csv']))->assertRedirect(route('login'));

        Gate::define('manage-team', fn () => false);

        $this->actingAs($this->user)
            ->get(route('audit.export', ['format' => 'csv']))
            ->assertForbidden();

        $this->assertCount(0, $this->exportEntries());
    }

    public function test_export_records_audit_exported_entry(): void
    {
        $this->actingAs($this->user)
            ->get(route('audit.export', ['format' => 'jsonl', 'event' => 'agent', 'from' => '2026-01-01']))
            ->assertOk();

        $entries = $this->exportEntries();
        $this->assertCount(1, $entries);
        $entry = $entries->first();
        $this->assertSame($this->team->id, $entry->team_id);
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertSame('jsonl', $entry->properties['format']);
        $this->assertSame('agent', $entry->properties['filters']['event']);
        $this->assertSame('2026-01-01', $entry->properties['filters']['from']);

        $status = $this->get(route('audit.export', ['format' => 'xml']))->getStatusCode();
        $this->assertContains($status, [302, 404, 422]);
        $this->assertCount(1, $this->exportEntries());

        $status = $this->getJson(route('audit.export', ['format' => 'xml']))->getStatusCode();
        $this->assertContains($status, [404, 422]);
        $this->assertCount(1, $this->exportEntries());
    }

    public function test_docs_page_describes_export(): void
    {
        $this->withoutVite();

        $this->get(route('docs.show', 'audit-log'))
            ->assertOk()
            ->assertSee('Exporting the audit log')
            ->assertSee('audit.exported');
    }

    public function test_csv_cells_are_formula_escaped(): void
    {
        $evil = User::factory()->create(['name' => '=HYPERLINK("x")']);
        $this->entry('=cmd.run', '2026-03-01 10:00:00', userId: $evil->id);
        $this->entry('@sum.x', '2026-03-02 10:00:00');
        $this->entry('+plus.x', '2026-03-03 10:00:00');
        $this->entry('-minus.x', '2026-03-04 10:00:00');

        $body = $this->actingAs($this->user)
            ->get(route('audit.export', ['format' => 'csv']))
            ->streamedContent();

        $rows = array_values(array_filter(
            array_map(
                fn ($line) => str_getcsv($line, ',', '"', ''),
                array_slice(array_values(array_filter(explode("\n", $body))), 1),
            ),
            fn ($row) => $row[2] !== 'audit.exported',
        ));

        $events = array_column($rows, 2);
        $this->assertSame(["'-minus.x", "'+plus.x", "'@sum.x", "'=cmd.run"], $events);
        $this->assertSame('\'=HYPERLINK("x")', $rows[3][4]);
    }
}
