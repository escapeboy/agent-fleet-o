<?php

namespace Tests\Feature\Domain\GitRepository;

use App\Domain\GitRepository\Services\TestRatchetGuard;
use Tests\TestCase;

class TestRatchetGuardTest extends TestCase
{
    private TestRatchetGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new TestRatchetGuard;
    }

    public function test_clean_diff_against_non_test_files_passes(): void
    {
        $verdict = $this->guard->inspect([
            ['path' => 'src/Foo.php', 'mode' => 'modify', 'content' => "<?php class Foo {}\n"],
            ['path' => 'README.md', 'mode' => 'modify', 'content' => "# Foo\n"],
        ]);

        $this->assertFalse($verdict->violation);
        $this->assertSame('no test files affected', $verdict->reason);
    }

    public function test_deleted_phpunit_test_file_is_violation(): void
    {
        $verdict = $this->guard->inspect([
            ['path' => 'tests/Feature/Domain/Foo/FooTest.php', 'mode' => 'delete'],
            ['path' => 'src/Foo.php', 'mode' => 'modify', 'content' => '<?php'],
        ]);

        $this->assertTrue($verdict->violation);
        $this->assertSame(['tests/Feature/Domain/Foo/FooTest.php'], $verdict->deletedTestFiles);
        $this->assertStringContainsString('1 test file(s) deleted', $verdict->reason);
    }

    public function test_modification_that_removes_three_assertions_is_violation(): void
    {
        $before = "    public function test_x() {\n        \$this->assertTrue(true);\n        \$this->assertSame(1, 1);\n        \$this->assertNull(null);\n        \$this->assertCount(0, []);\n    }\n";
        $after = "    public function test_x() {\n        \$this->assertTrue(true);\n    }\n";

        $verdict = $this->guard->inspect([
            ['path' => 'tests/Unit/X/XTest.php', 'mode' => 'modify', 'content' => $after, 'content_before' => $before],
        ]);

        $this->assertTrue($verdict->violation);
        $this->assertSame(['tests/Unit/X/XTest.php'], $verdict->modifiedTestFiles);
        $this->assertGreaterThanOrEqual(3, $verdict->removedAssertionCount);
    }

    public function test_jest_spec_deletion_is_violation(): void
    {
        $verdict = $this->guard->inspect([
            ['path' => 'src/__tests__/utils.spec.ts', 'mode' => 'delete'],
        ]);

        $this->assertTrue($verdict->violation);
        $this->assertSame(['src/__tests__/utils.spec.ts'], $verdict->deletedTestFiles);
    }

    public function test_modifying_test_to_add_assertions_is_not_violation(): void
    {
        $before = "    public function test_x() { \$this->assertTrue(true); }\n";
        $after = "    public function test_x() {\n        \$this->assertTrue(true);\n        \$this->assertSame(1, 1);\n    }\n";

        $verdict = $this->guard->inspect([
            ['path' => 'tests/Unit/X/XTest.php', 'mode' => 'modify', 'content' => $after, 'content_before' => $before],
        ]);

        $this->assertFalse($verdict->violation);
    }
}
