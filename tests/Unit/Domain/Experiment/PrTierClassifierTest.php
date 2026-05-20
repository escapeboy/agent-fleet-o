<?php

namespace Tests\Unit\Domain\Experiment;

use App\Domain\Experiment\Services\PrTierClassifier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PrTierClassifierTest extends TestCase
{
    private PrTierClassifier $classify;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classify = new PrTierClassifier;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{
     *     files_changed: list<string>,
     *     lines_added: int,
     *     lines_removed: int,
     *     target_branch: string,
     *     promote_branch: string,
     *     composer_json_changed?: bool,
     * }
     */
    private function diff(array $overrides = []): array
    {
        return array_merge([
            'files_changed' => ['resources/views/foo.blade.php'],
            'lines_added' => 5,
            'lines_removed' => 0,
            'target_branch' => 'develop',
            'promote_branch' => 'main',
            'composer_json_changed' => false,
        ], $overrides);
    }

    // ============================ T1 ============================

    public function test_t1_single_file_small_change(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['resources/views/foo.blade.php'],
            'lines_added' => 5,
        ]));

        $this->assertSame('T1', $result['tier']);
        $this->assertStringContainsString('1 file', $result['reason']);
        $this->assertStringContainsString('5 LOC', $result['reason']);
    }

    public function test_t1_two_files_small_change(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['README.md', 'CHANGELOG.md'],
            'lines_added' => 10,
            'lines_removed' => 2,
        ]));

        $this->assertSame('T1', $result['tier']);
        $this->assertStringContainsString('2 files', $result['reason']);
        $this->assertStringContainsString('12 LOC', $result['reason']);
    }

    public function test_t1_boundary_at_30_loc(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['resources/css/app.css', 'resources/views/header.blade.php'],
            'lines_added' => 30,
        ]));

        $this->assertSame('T1', $result['tier']);
    }

    public function test_t1_just_above_30_loc_bumps_to_t2(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['resources/css/app.css'],
            'lines_added' => 31,
        ]));

        $this->assertSame('T2', $result['tier']);
    }

    // ============================ T2 ============================

    public function test_t2_four_php_files_medium_loc(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => [
                'app/Livewire/Foo/Page.php',
                'app/Domain/Foo/Actions/CreateFooAction.php',
                'resources/views/livewire/foo/page.blade.php',
                'tests/Feature/FooPageTest.php',
            ],
            'lines_added' => 100,
            'lines_removed' => 42,
        ]));

        $this->assertSame('T2', $result['tier']);
        $this->assertStringContainsString('4 files', $result['reason']);
        $this->assertStringContainsString('142 LOC', $result['reason']);
    }

    public function test_t2_boundary_at_5_files_199_loc(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['a.php', 'b.php', 'c.php', 'd.php', 'e.php'],
            'lines_added' => 100,
            'lines_removed' => 99,
        ]));

        $this->assertSame('T2', $result['tier']);
    }

    public function test_t2_just_above_loc_threshold_bumps_to_t3(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['a.php', 'b.php', 'c.php'],
            'lines_added' => 201,
        ]));

        $this->assertSame('T3', $result['tier']);
    }

    public function test_t2_above_file_count_threshold_bumps_to_t3(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['a.php', 'b.php', 'c.php', 'd.php', 'e.php', 'f.php'],
            'lines_added' => 50,
        ]));

        $this->assertSame('T3', $result['tier']);
    }

    // ============================ T3 ============================

    public function test_t3_when_migration_file_touched(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['database/migrations/2026_05_10_000001_add_foo.php'],
            'lines_added' => 10,
        ]));

        $this->assertSame('T3', $result['tier']);
        $this->assertStringContainsString('migration', $result['reason']);
    }

    public function test_t3_when_migration_in_subpath(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['cloud/database/migrations/2026_05_10_x.php'],
            'lines_added' => 10,
        ]));

        $this->assertSame('T3', $result['tier']);
        $this->assertStringContainsString('migration', $result['reason']);
    }

    public function test_t3_when_auth_middleware_touched(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['app/Http/Middleware/AuthorizeRequest.php'],
            'lines_added' => 5,
        ]));

        $this->assertSame('T3', $result['tier']);
        $this->assertStringContainsString('auth', $result['reason']);
    }

    public function test_t3_when_domain_auth_module_touched(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['app/Domain/User/AuthServiceProvider.php'],
            'lines_added' => 8,
        ]));

        $this->assertSame('T3', $result['tier']);
        $this->assertStringContainsString('auth', $result['reason']);
    }

    public function test_t3_when_auth_config_touched(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['config/auth.php'],
            'lines_added' => 3,
        ]));

        $this->assertSame('T3', $result['tier']);
        $this->assertStringContainsString('auth', $result['reason']);
    }

    public function test_t3_when_sanctum_config_touched(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['config/sanctum.php'],
            'lines_added' => 2,
        ]));

        $this->assertSame('T3', $result['tier']);
    }

    public function test_t3_when_composer_json_changed(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['composer.json', 'composer.lock'],
            'lines_added' => 4,
            'composer_json_changed' => true,
        ]));

        $this->assertSame('T3', $result['tier']);
        $this->assertStringContainsString('composer', $result['reason']);
    }

    // ============================ T4 ============================

    public function test_t4_takes_precedence_over_t1_when_target_is_promote_branch(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['resources/css/app.css'],
            'lines_added' => 5,
            'target_branch' => 'main',
            'promote_branch' => 'main',
        ]));

        $this->assertSame('T4', $result['tier']);
        $this->assertStringContainsString('promote_branch', $result['reason']);
    }

    public function test_t4_takes_precedence_over_t3_migration(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['database/migrations/2026_05_10_x.php'],
            'lines_added' => 50,
            'target_branch' => 'master',
            'promote_branch' => 'master',
        ]));

        $this->assertSame('T4', $result['tier']);
    }

    public function test_t4_target_match_is_case_insensitive(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['app/Foo.php'],
            'lines_added' => 5,
            'target_branch' => 'MAIN',
            'promote_branch' => 'main',
        ]));

        $this->assertSame('T4', $result['tier']);
    }

    public function test_t4_does_not_trigger_when_target_differs_from_promote_branch(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['app/Foo.php'],
            'lines_added' => 5,
            'target_branch' => 'develop',
            'promote_branch' => 'main',
        ]));

        $this->assertNotSame('T4', $result['tier']);
    }

    public function test_t4_does_not_trigger_when_promote_branch_is_empty(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['app/Foo.php'],
            'lines_added' => 5,
            'target_branch' => '',
            'promote_branch' => '',
        ]));

        $this->assertNotSame('T4', $result['tier']);
    }

    // ============================ Edge cases ============================

    public function test_throws_when_files_changed_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ($this->classify)($this->diff(['files_changed' => []]));
    }

    public function test_zero_loc_with_single_file_is_t1(): void
    {
        // Binary file or rename — counted as a file change with no LOC delta.
        $result = ($this->classify)($this->diff([
            'files_changed' => ['public/img/logo.png'],
            'lines_added' => 0,
            'lines_removed' => 0,
        ]));

        $this->assertSame('T1', $result['tier']);
    }

    public function test_reason_format_uses_singular_for_one_file(): void
    {
        $result = ($this->classify)($this->diff([
            'files_changed' => ['x.php'],
            'lines_added' => 1,
        ]));

        $this->assertStringContainsString('1 file,', $result['reason']);
        $this->assertStringNotContainsString('1 files,', $result['reason']);
    }
}
