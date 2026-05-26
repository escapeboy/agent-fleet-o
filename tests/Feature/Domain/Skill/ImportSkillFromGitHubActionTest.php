<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\ImportSkillFromGitHubAction;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class ImportSkillFromGitHubActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'GitHub Team',
            'slug' => 'github-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
    }

    private function action(): ImportSkillFromGitHubAction
    {
        return app(ImportSkillFromGitHubAction::class);
    }

    private function md(string $name): string
    {
        return "---\nname: {$name}\ndescription: The {$name} skill.\n---\n\n## When to Use\n\nUse {$name}.\n\n## Boundaries\n\nNone.";
    }

    public function test_imports_a_single_skill_md_file(): void
    {
        Http::fake([
            'api.github.com/repos/octo/repo/contents/SKILL.md*' => Http::response([
                'type' => 'file',
                'name' => 'SKILL.md',
                'path' => 'SKILL.md',
                'encoding' => 'base64',
                'content' => base64_encode($this->md('solo')),
            ]),
        ]);

        $result = $this->action()->execute($this->team->id, 'octo/repo/SKILL.md');

        $this->assertCount(1, $result['imported']);
        $this->assertSame('solo', $result['imported'][0]->name);
        $this->assertSame([], $result['failed']);
    }

    public function test_imports_every_skill_in_a_directory(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/contents/skills/lead-scorer')) {
                return Http::response([[
                    'type' => 'file', 'name' => 'SKILL.md', 'path' => 'skills/lead-scorer/SKILL.md',
                    'download_url' => 'https://raw.githubusercontent.com/octo/repo/main/skills/lead-scorer/SKILL.md',
                ]]);
            }
            if (str_contains($url, '/contents/skills/triage')) {
                return Http::response([[
                    'type' => 'file', 'name' => 'SKILL.md', 'path' => 'skills/triage/SKILL.md',
                    'download_url' => 'https://raw.githubusercontent.com/octo/repo/main/skills/triage/SKILL.md',
                ]]);
            }
            if (str_contains($url, '/contents/skills')) {
                return Http::response([
                    ['type' => 'dir', 'name' => 'lead-scorer', 'path' => 'skills/lead-scorer'],
                    ['type' => 'dir', 'name' => 'triage', 'path' => 'skills/triage'],
                ]);
            }
            if (str_contains($url, 'raw.githubusercontent.com') && str_contains($url, 'lead-scorer')) {
                return Http::response($this->md('lead-scorer'));
            }
            if (str_contains($url, 'raw.githubusercontent.com') && str_contains($url, 'triage')) {
                return Http::response($this->md('triage'));
            }

            return Http::response('not found', 404);
        });

        $result = $this->action()->execute($this->team->id, 'octo/repo/skills');

        $names = array_map(static fn (Skill $s) => $s->name, $result['imported']);
        $this->assertCount(2, $result['imported']);
        $this->assertContains('lead-scorer', $names);
        $this->assertContains('triage', $names);
    }

    public function test_partial_failure_records_failed_and_imports_the_rest(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/contents/skills/good')) {
                return Http::response([[
                    'type' => 'file', 'name' => 'SKILL.md', 'path' => 'skills/good/SKILL.md',
                    'download_url' => 'https://raw.githubusercontent.com/octo/repo/main/skills/good/SKILL.md',
                ]]);
            }
            if (str_contains($url, '/contents/skills/bad')) {
                return Http::response([[
                    'type' => 'file', 'name' => 'SKILL.md', 'path' => 'skills/bad/SKILL.md',
                    'download_url' => 'https://raw.githubusercontent.com/octo/repo/main/skills/bad/SKILL.md',
                ]]);
            }
            if (str_contains($url, '/contents/skills')) {
                return Http::response([
                    ['type' => 'dir', 'name' => 'good', 'path' => 'skills/good'],
                    ['type' => 'dir', 'name' => 'bad', 'path' => 'skills/bad'],
                ]);
            }
            if (str_contains($url, 'raw.githubusercontent.com') && str_contains($url, 'good')) {
                return Http::response($this->md('good'));
            }
            if (str_contains($url, 'raw.githubusercontent.com') && str_contains($url, 'bad')) {
                return Http::response('this is not a valid SKILL.md');
            }

            return Http::response('not found', 404);
        });

        $result = $this->action()->execute($this->team->id, 'octo/repo/skills');

        $this->assertCount(1, $result['imported']);
        $this->assertSame('good', $result['imported'][0]->name);
        $this->assertArrayHasKey('skills/bad/SKILL.md', $result['failed']);
    }

    public function test_not_found_path_throws(): void
    {
        Http::fake(['api.github.com/*' => Http::response('not found', 404)]);

        $this->expectException(InvalidArgumentException::class);
        $this->action()->execute($this->team->id, 'octo/repo/missing');
    }

    public function test_invalid_source_throws_without_calling_github(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->action()->execute($this->team->id, 'just-one-segment');
    }

    public function test_ref_is_passed_to_the_github_api(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                'type' => 'file', 'name' => 'SKILL.md', 'path' => 'SKILL.md',
                'encoding' => 'base64', 'content' => base64_encode($this->md('pinned')),
            ]),
        ]);

        $this->action()->execute($this->team->id, 'octo/repo/SKILL.md@v1');

        Http::assertSent(static fn ($request) => str_contains($request->url(), 'ref=v1'));
    }

    public function test_token_is_sent_as_bearer_header(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                'type' => 'file', 'name' => 'SKILL.md', 'path' => 'SKILL.md',
                'encoding' => 'base64', 'content' => base64_encode($this->md('private')),
            ]),
        ]);

        $this->action()->execute($this->team->id, 'octo/repo/SKILL.md', token: 'ghp_secret');

        Http::assertSent(static fn ($request) => $request->hasHeader('Authorization', 'Bearer ghp_secret'));
    }
}
