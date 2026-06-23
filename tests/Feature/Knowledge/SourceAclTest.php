<?php

namespace Tests\Feature\Knowledge;

use App\Domain\Knowledge\Services\SourceAclGate;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\UserGroupResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SourceAclTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_adds_source_acl_column(): void
    {
        $this->assertTrue(Schema::hasColumn('knowledge_chunks', 'source_acl'));
    }

    public function test_gate_allows_unrestricted_and_overlapping(): void
    {
        $gate = new SourceAclGate;

        $this->assertTrue($gate->allows(null, []), 'null ACL = unrestricted');
        $this->assertTrue($gate->allows(['allowed_group_slugs' => []], []), 'empty ACL = unrestricted');
        $this->assertTrue($gate->allows(['allowed_group_slugs' => ['g1']], ['g1', 'g2']), 'overlap visible');
        $this->assertFalse($gate->allows(['allowed_group_slugs' => ['g1']], ['g2']), 'no overlap hidden');
    }

    public function test_sql_clause_empty_when_disabled(): void
    {
        config(['source_acl.enabled' => false]);
        $this->assertSame('', (new SourceAclGate)->sqlClause(['team:x'])['sql']);
    }

    public function test_sql_clause_restricts_to_unrestricted_when_no_groups(): void
    {
        config(['source_acl.enabled' => true]);
        $clause = (new SourceAclGate)->sqlClause([]);
        $this->assertStringContainsString('source_acl IS NULL', $clause['sql']);
        $this->assertSame([], $clause['bindings']);
    }

    public function test_sql_clause_uses_jsonb_exists_any_with_bindings(): void
    {
        config(['source_acl.enabled' => true]);
        $clause = (new SourceAclGate)->sqlClause(['team:1', 'team:1:role:admin']);
        $this->assertStringContainsString('jsonb_exists_any', $clause['sql']);
        $this->assertStringContainsString('source_acl IS NULL OR', $clause['sql']);
        $this->assertSame(['team:1', 'team:1:role:admin'], $clause['bindings']);
    }

    public function test_user_group_resolver_returns_team_and_role_groups(): void
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'ACL Team',
            'slug' => 'acl-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->teams()->attach($team->id, ['role' => 'admin']);

        $groups = (new UserGroupResolver)->groupsFor($user, $team->id);

        $this->assertContains("team:{$team->id}", $groups);
        $this->assertContains("team:{$team->id}:role:admin", $groups);
    }

    public function test_user_group_resolver_empty_without_user_or_team(): void
    {
        $resolver = new UserGroupResolver;
        $this->assertSame([], $resolver->groupsFor(null, 'team-x'));
        $this->assertSame([], $resolver->groupsFor(User::factory()->create(), null));
    }
}
