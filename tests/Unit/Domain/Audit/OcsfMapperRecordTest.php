<?php

namespace Tests\Unit\Domain\Audit;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OcsfMapperRecordTest extends TestCase
{
    public function test_to_record_maps_entry_fields(): void
    {
        $user = (new User)->forceFill(['id' => 'user-1', 'name' => 'Alice']);

        $entry = (new AuditEntry)->forceFill([
            'id' => 'entry-1',
            'team_id' => 'team-1',
            'user_id' => 'user-1',
            'event' => 'experiment.created',
            'ocsf_class_uid' => 3001,
            'ocsf_severity_id' => 3,
            'subject_type' => 'App\\Domain\\Experiment\\Models\\Experiment',
            'subject_id' => 'exp-1',
            'properties' => ['foo' => 'bar'],
            'ip_address' => '203.0.113.7',
            'triggered_by' => 'api:token',
            'created_at' => Carbon::parse('2026-03-01 10:00:00', 'UTC'),
        ]);
        $entry->setRelation('user', $user);

        $record = OcsfMapper::toRecord($entry);

        $this->assertSame(3001, $record['class_uid']);
        $this->assertSame(3, $record['category_uid']);
        $this->assertSame(3, $record['severity_id']);
        $this->assertSame(300199, $record['type_uid']);
        $this->assertSame(99, $record['activity_id']);
        $this->assertSame('experiment.created', $record['activity_name']);
        $this->assertSame(Carbon::parse('2026-03-01 10:00:00', 'UTC')->getTimestampMs(), $record['time']);
        $this->assertSame('1.1.0', $record['metadata']['version']);
        $this->assertSame('entry-1', $record['metadata']['uid']);
        $this->assertSame(['name' => 'FleetQ', 'vendor_name' => 'FleetQ'], $record['metadata']['product']);
        $this->assertSame(['uid' => 'user-1', 'name' => 'Alice'], $record['actor']['user']);
        $this->assertSame('203.0.113.7', $record['src_endpoint']['ip']);
        $this->assertSame(['foo' => 'bar'], $record['unmapped']['properties']);
        $this->assertSame('exp-1', $record['unmapped']['subject_id']);
        $this->assertSame('api:token', $record['unmapped']['triggered_by']);
    }

    public function test_to_record_falls_back_to_classify_without_stored_values(): void
    {
        $entry = (new AuditEntry)->forceFill([
            'id' => 'entry-2',
            'event' => 'budget.exceeded',
            'created_at' => Carbon::parse('2026-03-01 10:00:00', 'UTC'),
        ]);

        $record = OcsfMapper::toRecord($entry);
        $expected = OcsfMapper::classify('budget.exceeded');

        $this->assertSame($expected['class_uid'], $record['class_uid']);
        $this->assertSame($expected['severity_id'], $record['severity_id']);
        $this->assertSame(intdiv($expected['class_uid'], 1000), $record['category_uid']);
        $this->assertArrayNotHasKey('actor', $record);
        $this->assertArrayNotHasKey('src_endpoint', $record);
    }
}
