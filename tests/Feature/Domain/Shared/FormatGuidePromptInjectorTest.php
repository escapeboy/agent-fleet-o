<?php

namespace Tests\Feature\Domain\Shared;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\FormatGuidePromptInjector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormatGuidePromptInjectorTest extends TestCase
{
    use RefreshDatabase;

    private function team(array $settings): Team
    {
        $user = User::factory()->create();

        return Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'settings' => $settings,
        ]);
    }

    public function test_appends_the_guide_when_present(): void
    {
        $team = $this->team(['format_guide' => 'Primary colour is #FF4F18. Active voice only.']);

        $result = app(FormatGuidePromptInjector::class)->inject('You are a writer.', $team->id);

        $this->assertStringContainsString('You are a writer.', $result);
        $this->assertStringContainsString('Team Format & Brand Guide', $result);
        $this->assertStringContainsString('#FF4F18', $result);
    }

    public function test_returns_prompt_unchanged_when_no_guide_key(): void
    {
        $team = $this->team([]);

        $result = app(FormatGuidePromptInjector::class)->inject('You are a writer.', $team->id);

        $this->assertSame('You are a writer.', $result);
    }

    public function test_returns_prompt_unchanged_for_empty_or_whitespace_guide(): void
    {
        $empty = $this->team(['format_guide' => '']);
        $blank = $this->team(['format_guide' => "   \n  "]);

        $injector = app(FormatGuidePromptInjector::class);

        $this->assertSame('Base.', $injector->inject('Base.', $empty->id));
        $this->assertSame('Base.', $injector->inject('Base.', $blank->id));
    }

    public function test_returns_prompt_unchanged_for_null_team(): void
    {
        $result = app(FormatGuidePromptInjector::class)->inject('Base prompt.', null);

        $this->assertSame('Base prompt.', $result);
    }

    public function test_returns_prompt_unchanged_for_unknown_team(): void
    {
        $result = app(FormatGuidePromptInjector::class)->inject('Base prompt.', 'non-existent-id');

        $this->assertSame('Base prompt.', $result);
    }
}
