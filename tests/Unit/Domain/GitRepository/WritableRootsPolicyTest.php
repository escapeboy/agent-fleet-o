<?php

namespace Tests\Unit\Domain\GitRepository;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\GitRepository\DTOs\WarmBuildChangeset;
use App\Domain\GitRepository\DTOs\WritableRootsGrant;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\ChangesetPolicyValidator;
use App\Domain\GitRepository\Services\WritableRootsPolicy;
use Tests\TestCase;

class WritableRootsPolicyTest extends TestCase
{
    private function policy(): WritableRootsPolicy
    {
        return new WritableRootsPolicy;
    }

    private function changeset(array $files): WarmBuildChangeset
    {
        return new WarmBuildChangeset(files: $files, added: 1, removed: 0, headSha: 'deadbeef');
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['experiments.warm_build.writable_roots' => [
            'denied_globs' => ['database/migrations/**', '**/migrations/**'],
            'allowed_roots' => [],
        ]]);
    }

    // ---- WritableRootsGrant::permits ----

    public function test_empty_allow_roots_permits_anything_not_denied(): void
    {
        $grant = new WritableRootsGrant(allowedRoots: [], deniedGlobs: ['database/migrations/**']);
        $this->assertTrue($grant->permits('app/Domain/Foo.php'));
        $this->assertTrue($grant->permits('tests/FooTest.php'));
    }

    public function test_denied_glob_blocks_migration(): void
    {
        $grant = new WritableRootsGrant(allowedRoots: [], deniedGlobs: ['database/migrations/**', '**/migrations/**']);
        $this->assertFalse($grant->permits('database/migrations/2026_07_08_x.php'));
        $this->assertFalse($grant->permits('modules/Billing/database/migrations/x.php'));
    }

    public function test_non_empty_allow_roots_restrict_writes(): void
    {
        $grant = new WritableRootsGrant(allowedRoots: ['app', 'tests'], deniedGlobs: []);
        $this->assertTrue($grant->permits('app/Foo.php'));
        $this->assertTrue($grant->permits('tests/Bar.php'));
        $this->assertFalse($grant->permits('config/app.php'));
    }

    public function test_deny_wins_over_allow(): void
    {
        // migrations denied even though its parent dir is an allowed root
        $grant = new WritableRootsGrant(allowedRoots: ['database'], deniedGlobs: ['database/migrations/**']);
        $this->assertTrue($grant->permits('database/seeders/X.php'));
        $this->assertFalse($grant->permits('database/migrations/X.php'));
    }

    // ---- WritableRootsPolicy::resolve (layering) ----

    public function test_resolve_default_grant(): void
    {
        $grant = $this->policy()->resolve(new GitRepository);
        $this->assertSame([], $grant->allowedRoots);
        $this->assertContains('database/migrations/**', $grant->deniedGlobs);
    }

    public function test_repo_override_adds_denied_and_sets_allowed(): void
    {
        $repo = new GitRepository;
        $repo->config = ['warm_build_writable_roots' => [
            'allowed_roots' => ['app'],
            'denied_globs' => ['config/**'],
        ]];

        $grant = $this->policy()->resolve($repo);

        $this->assertSame(['app'], $grant->allowedRoots);
        $this->assertContains('config/**', $grant->deniedGlobs);
        // default denials accumulate — repo layer can only ADD denials
        $this->assertContains('database/migrations/**', $grant->deniedGlobs);
    }

    public function test_task_override_wins_allowed_and_accumulates_denied(): void
    {
        $repo = new GitRepository;
        $repo->config = ['warm_build_writable_roots' => ['allowed_roots' => ['app']]];
        $exp = new Experiment;
        $exp->constraints = ['warm_build_writable_roots' => [
            'allowed_roots' => ['src'],
            'denied_globs' => ['src/legacy/**'],
        ]];

        $grant = $this->policy()->resolve($repo, $exp);

        $this->assertSame(['src'], $grant->allowedRoots, 'task allow-list is most specific');
        $this->assertContains('src/legacy/**', $grant->deniedGlobs);
        $this->assertContains('database/migrations/**', $grant->deniedGlobs);
    }

    // ---- absoluteWritablePaths (for #2 Landlock) ----

    public function test_absolute_paths_whole_tree_when_no_allow_roots(): void
    {
        $grant = new WritableRootsGrant(allowedRoots: [], deniedGlobs: ['x']);
        $this->assertSame(['/wt/run'], $this->policy()->absoluteWritablePaths($grant, '/wt/run/'));
    }

    public function test_absolute_paths_map_allow_roots_under_worktree(): void
    {
        $grant = new WritableRootsGrant(allowedRoots: ['app', 'tests'], deniedGlobs: []);
        $this->assertSame(
            ['/wt/run/app', '/wt/run/tests'],
            $this->policy()->absoluteWritablePaths($grant, '/wt/run'),
        );
    }

    // ---- ChangesetPolicyValidator ----

    public function test_validator_flags_only_out_of_root_paths(): void
    {
        $grant = new WritableRootsGrant(allowedRoots: [], deniedGlobs: ['database/migrations/**']);
        $changeset = $this->changeset([
            'app/Domain/Foo.php',
            'database/migrations/2026_07_08_x.php',
            'tests/FooTest.php',
        ]);

        $violations = (new ChangesetPolicyValidator)->violations($changeset, $grant);

        $this->assertSame(['database/migrations/2026_07_08_x.php'], $violations);
        $this->assertFalse((new ChangesetPolicyValidator)->isWithinRoots($changeset, $grant));
    }

    public function test_validator_clean_changeset_within_roots(): void
    {
        $grant = new WritableRootsGrant(allowedRoots: [], deniedGlobs: ['database/migrations/**']);
        $changeset = $this->changeset(['app/Foo.php', 'tests/Bar.php']);

        $this->assertSame([], (new ChangesetPolicyValidator)->violations($changeset, $grant));
        $this->assertTrue((new ChangesetPolicyValidator)->isWithinRoots($changeset, $grant));
    }
}
