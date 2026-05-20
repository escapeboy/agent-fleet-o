<?php

namespace Tests\Feature\Domain\Email;

use App\Domain\Email\Models\EmailTheme;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression: EmailTemplateController previously accepted any email_theme_id
 * because the `exists:email_themes,id` rule didn't scope by team_id, so a
 * malicious user could attach another team's email_theme_id to their own
 * template — successfully cross-binding tenant data.
 *
 * Fix landed 2026-05-20 as part of the scoped-exists sweep. See
 * docs/architecture-test-pattern.md.
 */
class EmailTemplateScopedExistsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_rejects_email_theme_from_another_team(): void
    {
        $foreignTeam = Team::factory()->create();
        $foreignTheme = EmailTheme::create([
            'team_id' => $foreignTeam->id,
            'name' => 'Foreign theme',
            'status' => 'draft',
        ]);

        $ownTeam = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $ownTeam->id]);
        $user->teams()->attach($ownTeam, ['role' => 'owner']);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/email-templates', [
            'name' => 'Cross-tenant attempt',
            'email_theme_id' => $foreignTheme->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email_theme_id']);
    }
}
