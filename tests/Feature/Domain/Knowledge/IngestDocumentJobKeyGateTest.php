<?php

namespace Tests\Feature\Domain\Knowledge;

use App\Domain\Knowledge\Enums\KnowledgeBaseStatus;
use App\Domain\Knowledge\Jobs\IngestDocumentJob;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Knowledge\Services\PrismEmbeddingsProvider;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #872/#871: with no reachable embedding key (team BYOK, platform, or env) the
 * ingest job must skip gracefully — mark the KB errored — instead of attempting
 * an embedding call that throws a provider 401 into Sentry.
 */
class IngestDocumentJobKeyGateTest extends TestCase
{
    use RefreshDatabase;

    private function makeKnowledgeBase(): KnowledgeBase
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'KB Team',
            'slug' => 'kb-team-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        return KnowledgeBase::create([
            'team_id' => $team->id,
            'name' => 'Docs',
            'status' => KnowledgeBaseStatus::Idle,
        ]);
    }

    public function test_skips_and_errors_when_no_embedding_key(): void
    {
        config([
            'prism.providers.openai.api_key' => null,
            'services.platform_api_keys.openai' => null,
        ]);

        $kb = $this->makeKnowledgeBase();

        $embedder = $this->createMock(PrismEmbeddingsProvider::class);
        $embedder->expects($this->never())->method('embedDocuments');

        (new IngestDocumentJob($kb->id, 'some content to embed'))->handle($embedder);

        $this->assertSame(KnowledgeBaseStatus::Error, $kb->fresh()->status);
    }
}
