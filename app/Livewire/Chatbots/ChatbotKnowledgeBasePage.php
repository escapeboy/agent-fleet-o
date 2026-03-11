<?php

namespace App\Livewire\Chatbots;

use App\Domain\Chatbot\Actions\CreateKnowledgeSourceAction;
use App\Domain\Chatbot\Actions\DeleteKnowledgeSourceAction;
use App\Domain\Chatbot\Jobs\IndexKnowledgeSourceJob;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotKnowledgeSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithFileUploads;
use Prism\Prism\Facades\Prism;

class ChatbotKnowledgeBasePage extends Component
{
    use WithFileUploads;

    public Chatbot $chatbot;

    // Add source form
    public bool $showAddForm = false;

    public string $sourceType = 'url';

    public string $sourceName = '';

    public string $sourceUrl = '';

    public $sourceFile = null;

    // RAG test
    public string $testQuery = '';

    public array $testResults = [];

    public bool $testRunning = false;

    public function mount(Chatbot $chatbot): void
    {
        $this->chatbot = $chatbot;
    }

    public function addSource(): void
    {
        $this->validate([
            'sourceName' => 'required|string|max:255',
            'sourceType' => 'required|in:url,sitemap,document',
            'sourceUrl' => 'required_if:sourceType,url,sitemap|nullable|url|max:2048',
            'sourceFile' => 'required_if:sourceType,document|nullable|file|max:10240|mimes:txt,md,pdf',
        ]);

        $data = [
            'name' => $this->sourceName,
            'type' => $this->sourceType,
        ];

        if ($this->sourceType === 'document' && $this->sourceFile) {
            $path = $this->sourceFile->store('chatbot-knowledge/'.$this->chatbot->id, 'local');
            $data['source_data'] = [
                'path' => $path,
                'mime_type' => $this->sourceFile->getMimeType(),
                'original_name' => $this->sourceFile->getClientOriginalName(),
                'size' => $this->sourceFile->getSize(),
            ];
        } else {
            $data['source_url'] = $this->sourceUrl;
        }

        app(CreateKnowledgeSourceAction::class)->execute($this->chatbot, $data);

        $this->reset(['showAddForm', 'sourceName', 'sourceType', 'sourceUrl', 'sourceFile']);
        session()->flash('message', 'Knowledge source added and queued for indexing.');
    }

    public function reindex(string $sourceId): void
    {
        $source = ChatbotKnowledgeSource::where('id', $sourceId)
            ->where('chatbot_id', $this->chatbot->id)
            ->firstOrFail();

        $source->update(['status' => 'pending', 'error_message' => null]);
        IndexKnowledgeSourceJob::dispatch($source->id)->onQueue('ai-calls');

        session()->flash('message', 'Re-indexing queued.');
    }

    public function deleteSource(string $sourceId): void
    {
        $source = ChatbotKnowledgeSource::where('id', $sourceId)
            ->where('chatbot_id', $this->chatbot->id)
            ->firstOrFail();

        app(DeleteKnowledgeSourceAction::class)->execute($source);
        session()->flash('message', 'Knowledge source deleted.');
    }

    public function runRagTest(): void
    {
        $this->validate(['testQuery' => 'required|string|min:3|max:500']);

        $this->testRunning = true;
        $this->testResults = [];

        try {
            $embedding = $this->generateTestEmbedding($this->testQuery);

            $rows = DB::select(
                'SELECT ckc.id, ckc.content, ckc.chunk_index, cks.name as source_name,
                        1 - (ckc.embedding <=> ?::vector) AS similarity
                 FROM chatbot_kb_chunks ckc
                 JOIN chatbot_knowledge_sources cks ON cks.id = ckc.source_id
                 WHERE ckc.chatbot_id = ?
                   AND ckc.team_id = ?
                   AND ckc.embedding IS NOT NULL
                   AND 1 - (ckc.embedding <=> ?::vector) >= 0.5
                 ORDER BY ckc.embedding <=> ?::vector
                 LIMIT 5',
                [$embedding, $this->chatbot->id, $this->chatbot->team_id, $embedding, $embedding],
            );

            $this->testResults = array_map(fn ($row) => [
                'id' => $row->id,
                'content' => mb_substr($row->content, 0, 300),
                'source_name' => $row->source_name,
                'chunk_index' => $row->chunk_index,
                'similarity' => round((float) $row->similarity, 4),
            ], $rows);
        } catch (\Throwable $e) {
            Log::warning('RAG test failed', ['error' => $e->getMessage()]);
            session()->flash('error', 'RAG test failed: '.$e->getMessage());
        } finally {
            $this->testRunning = false;
        }
    }

    private function generateTestEmbedding(string $text): string
    {
        $response = Prism::embeddings()
            ->using('openai', 'text-embedding-3-small')
            ->fromInput($text)
            ->asEmbeddings();

        $vector = $response->embeddings[0]->embedding;

        return '['.implode(',', $vector).']';
    }

    public function render()
    {
        $team = auth()->user()->currentTeam;

        if (! ($team->settings['chatbot_enabled'] ?? false)) {
            return $this->redirect(route('dashboard'));
        }

        $sources = ChatbotKnowledgeSource::where('chatbot_id', $this->chatbot->id)
            ->withTrashed(false)
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.chatbots.chatbot-knowledge-base-page', [
            'sources' => $sources,
        ])->layout('layouts.app', ['header' => $this->chatbot->name.' — Knowledge Base']);
    }
}
