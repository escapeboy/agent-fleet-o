<?php

namespace App\Domain\Skill\Jobs;

use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillEmbedding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateSkillEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly string $skillId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $skill = Skill::find($this->skillId);
        if (! $skill) {
            return;
        }

        $content = $this->buildContent($skill);
        $embedding = $this->generateEmbedding($content);

        if ($embedding === null) {
            return;
        }

        SkillEmbedding::updateOrCreate(
            ['skill_id' => $skill->id],
            [
                'content' => $content,
                'embedding' => $embedding,
            ],
        );

        // Store raw vector string directly (bypass array cast for pgvector)
        DB::table('skill_embeddings')
            ->where('skill_id', $skill->id)
            ->update(['embedding' => '['.implode(',', $embedding).']']);
    }

    private function buildContent(Skill $skill): string
    {
        $parts = [
            $skill->name,
            $skill->description ?? '',
        ];

        if (! empty($skill->input_schema['properties'])) {
            $inputKeys = implode(', ', array_keys($skill->input_schema['properties']));
            $parts[] = "Inputs: {$inputKeys}";
        }

        if (! empty($skill->meta['tags'])) {
            $tags = is_array($skill->meta['tags']) ? implode(', ', $skill->meta['tags']) : $skill->meta['tags'];
            $parts[] = "Tags: {$tags}";
        }

        return implode('. ', array_filter($parts));
    }

    private function generateEmbedding(string $text): ?array
    {
        $apiKey = config('prism.providers.openai.api_key') ?? env('OPENAI_API_KEY');
        if (empty($apiKey)) {
            Log::warning('GenerateSkillEmbeddingJob: no OpenAI API key configured, skipping embedding.');

            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->post('https://api.openai.com/v1/embeddings', [
                    'input' => $text,
                    'model' => config('skills.hybrid_retrieval.embedding_model', 'text-embedding-3-small'),
                ])
                ->throw()
                ->json();

            return $response['data'][0]['embedding'] ?? null;
        } catch (\Throwable $e) {
            Log::error('GenerateSkillEmbeddingJob: failed to generate embedding', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
