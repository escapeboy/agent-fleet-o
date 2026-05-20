<?php

namespace Tests\Unit\Domain\Skill\Actions;

use App\Domain\Skill\Actions\BreakingChangeDetector;
use App\Domain\Skill\Models\SkillVersion;
use Tests\TestCase;

class BreakingChangeDetectorTest extends TestCase
{
    private BreakingChangeDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new BreakingChangeDetector;
    }

    private function makeVersion(array $schema): SkillVersion
    {
        return new SkillVersion(['input_schema' => $schema]);
    }

    public function test_detects_field_removed(): void
    {
        $old = $this->makeVersion([
            'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string']],
            'required' => ['name', 'email'],
        ]);
        $new = $this->makeVersion([
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ]);

        $changes = $this->detector->execute($old, $new);

        $this->assertCount(1, $changes);
        $this->assertSame('field_removed', $changes[0]->kind);
        $this->assertSame('email', $changes[0]->field);
    }

    public function test_detects_type_narrowed(): void
    {
        $old = $this->makeVersion([
            'properties' => ['id' => ['type' => ['string', 'integer']]],
        ]);
        $new = $this->makeVersion([
            'properties' => ['id' => ['type' => 'string']],
        ]);

        $changes = $this->detector->execute($old, $new);

        $this->assertCount(1, $changes);
        $this->assertSame('type_narrowed', $changes[0]->kind);
        $this->assertSame('id', $changes[0]->field);
    }

    public function test_detects_enum_value_removed(): void
    {
        $old = $this->makeVersion([
            'properties' => ['status' => ['type' => 'string', 'enum' => ['draft', 'active', 'archived']]],
        ]);
        $new = $this->makeVersion([
            'properties' => ['status' => ['type' => 'string', 'enum' => ['draft', 'active']]],
        ]);

        $changes = $this->detector->execute($old, $new);

        $this->assertCount(1, $changes);
        $this->assertSame('enum_value_removed', $changes[0]->kind);
        $this->assertSame('status', $changes[0]->field);
        $this->assertSame('archived', $changes[0]->oldValue);
    }

    public function test_detects_required_added(): void
    {
        $old = $this->makeVersion([
            'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string']],
            'required' => ['name'],
        ]);
        $new = $this->makeVersion([
            'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string']],
            'required' => ['name', 'email'],
        ]);

        $changes = $this->detector->execute($old, $new);

        $this->assertCount(1, $changes);
        $this->assertSame('required_added', $changes[0]->kind);
        $this->assertSame('email', $changes[0]->field);
    }

    public function test_returns_empty_when_no_breaking_changes(): void
    {
        $old = $this->makeVersion([
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ]);
        $new = $this->makeVersion([
            'properties' => ['name' => ['type' => 'string'], 'optional_field' => ['type' => 'string']],
            'required' => ['name'],
        ]);

        $this->assertSame([], $this->detector->execute($old, $new));
    }
}
