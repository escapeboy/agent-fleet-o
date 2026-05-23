<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Shared;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\BrandVoiceValidator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandVoiceValidatorTest extends TestCase
{
    use RefreshDatabase;

    private function team(array $brandVoice): Team
    {
        $user = User::factory()->create();

        return Team::create([
            'name' => 'BV '.bin2hex(random_bytes(3)),
            'slug' => 'bv-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'settings' => $brandVoice === [] ? [] : ['brand_voice' => $brandVoice],
        ]);
    }

    public function test_passes_when_no_policy_configured(): void
    {
        $team = $this->team([]);

        $result = app(BrandVoiceValidator::class)->validate('Anything goes here.', $team->id);

        $this->assertTrue($result->passed);
        $this->assertSame([], $result->violations);
    }

    public function test_flags_forbidden_phrase_case_insensitively(): void
    {
        $team = $this->team(['forbidden_phrases' => ['synergy', 'circle back']]);

        $result = app(BrandVoiceValidator::class)->validate('Let us leverage SYNERGY today.', $team->id);

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->violations);
        $this->assertStringContainsString('synergy', $result->violations[0]);
    }

    public function test_flags_glossary_term_with_preferred_alternative(): void
    {
        $team = $this->team(['glossary' => [['term' => 'users', 'preferred' => 'customers']]]);

        $result = app(BrandVoiceValidator::class)->validate('Our users love it.', $team->id);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('customers', $result->violations[0]);
        $this->assertStringContainsString('users', $result->violations[0]);
    }

    public function test_passes_clean_content(): void
    {
        $team = $this->team([
            'forbidden_phrases' => ['synergy'],
            'glossary' => [['term' => 'users', 'preferred' => 'customers']],
        ]);

        $result = app(BrandVoiceValidator::class)->validate('Our customers are delighted.', $team->id);

        $this->assertTrue($result->passed);
    }

    public function test_passes_for_null_team(): void
    {
        $result = app(BrandVoiceValidator::class)->validate('synergy synergy', null);

        $this->assertTrue($result->passed);
    }
}
