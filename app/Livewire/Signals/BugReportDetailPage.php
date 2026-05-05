<?php

namespace App\Livewire\Signals;

use App\Domain\Agent\Models\Agent;
use App\Domain\Signal\Actions\AddSignalCommentAction;
use App\Domain\Signal\Actions\AssignSignalAction;
use App\Domain\Signal\Actions\DelegateBugReportToAgentAction;
use App\Domain\Signal\Actions\UpdateSignalStatusAction;
use App\Domain\Signal\Enums\CommentAuthorType;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Exceptions\InvalidSignalTransitionException;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Services\CommentAttachmentIngester;
use App\Domain\Signal\Services\SignalStatusTransitionMap;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class BugReportDetailPage extends Component
{
    use WithFileUploads;

    public Signal $signal;

    public string $commentText = '';

    public bool $commentVisibleToReporter = true;

    public string $delegateAgentId = '';

    public bool $showAssignModal = false;

    #[Validate('nullable|string|uuid')]
    public ?string $assignUserId = null;

    #[Validate('nullable|string|max:2000')]
    public ?string $assignReason = null;

    /** @var array<int, TemporaryUploadedFile> */
    public array $commentImages = [];

    public function mount(Signal $signal): void
    {
        abort_if($signal->source_type !== 'bug_report', 404);
        $this->signal = $signal;
    }

    public function updateStatus(string $status): void
    {
        try {
            $newStatus = SignalStatus::from($status);
            app(UpdateSignalStatusAction::class)->execute(
                signal: $this->signal,
                newStatus: $newStatus,
                actor: auth()->user(),
            );
            $this->signal->refresh();
        } catch (InvalidSignalTransitionException $e) {
            $this->addError('status', $e->getMessage());
        }
    }

    public function addComment(): void
    {
        $maxAttachments = (int) config('signals.bug_report.widget_comment_max_attachments', 4);
        $maxMb = (int) config('signals.bug_report.widget_comment_max_attachment_mb', 5);

        $this->validate([
            'commentText' => ['nullable', 'string', 'max:5000'],
            'commentImages' => ['nullable', 'array', 'max:'.$maxAttachments],
            'commentImages.*' => [
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp,gif',
                'max:'.($maxMb * 1024),
            ],
        ]);

        $body = trim($this->commentText);

        if ($body === '' && $this->commentImages === []) {
            $this->addError('commentText', 'Add a comment or attach at least one image.');

            return;
        }

        // "Visible to reporter" → support (shown in widget); unchecked → human (internal note).
        $authorType = $this->commentVisibleToReporter
            ? CommentAuthorType::Support
            : CommentAuthorType::Human;

        $comment = app(AddSignalCommentAction::class)->execute(
            signal: $this->signal,
            body: $body,
            authorType: $authorType,
            userId: auth()->id(),
        );

        if ($this->commentImages !== []) {
            $ingester = app(CommentAttachmentIngester::class);
            foreach ($this->commentImages as $upload) {
                $ingester->attachReencodedImage(
                    $comment,
                    $upload->getRealPath(),
                    $upload->getClientOriginalName(),
                );
            }
        }

        $this->commentText = '';
        $this->commentImages = [];
        $this->signal->load('comments.user', 'comments.media');
    }

    public function removeCommentImage(int $index): void
    {
        if (! array_key_exists($index, $this->commentImages)) {
            return;
        }

        array_splice($this->commentImages, $index, 1);
    }

    public function delegateToAgent(): void
    {
        $this->validate(['delegateAgentId' => ['required', 'uuid']]);

        $agent = Agent::find($this->delegateAgentId);
        abort_if(! $agent, 404);

        app(DelegateBugReportToAgentAction::class)->execute(
            signal: $this->signal,
            actor: auth()->user(),
            agentId: $agent->id,
        );

        $this->signal->refresh();
        $this->delegateAgentId = '';
    }

    public function openAssignModal(): void
    {
        $this->assignUserId = $this->signal->assigned_user_id;
        $this->assignReason = null;
        $this->showAssignModal = true;
    }

    public function submitAssign(): void
    {
        $this->validate();

        $assignee = $this->assignUserId ? User::find($this->assignUserId) : null;
        $actor = auth()->user();

        try {
            app(AssignSignalAction::class)->execute(
                signal: $this->signal,
                assignee: $assignee,
                actor: $actor,
                reason: $this->assignReason ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addError('assignUserId', $e->getMessage());

            return;
        }

        $this->signal->refresh();
        $this->showAssignModal = false;
        $this->assignUserId = null;
        $this->assignReason = null;
    }

    public function render(): View
    {
        $transitionMap = app(SignalStatusTransitionMap::class);
        $allowedTransitions = $transitionMap->allowedTransitionsFrom(
            $this->signal->status ?? SignalStatus::Received,
        );

        $agents = Agent::query()->orderBy('name')->get(['id', 'name']);

        $this->signal->load('comments.user', 'comments.media', 'assignedUser');

        $teamMembers = User::whereHas('teams', fn ($q) => $q->where('teams.id', auth()->user()->current_team_id))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('livewire.signals.bug-report-detail', [
            'allowedTransitions' => $allowedTransitions,
            'agents' => $agents,
            'mediaFiles' => $this->signal->getMedia('bug_report_files'),
            'teamMembers' => $teamMembers,
        ]);
    }
}
