<?php

namespace App\Domain\Experiment\Services;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Agent\Models\SandboxFileActivity;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\DTOs\TimelineEntry;
use App\Domain\Experiment\Enums\TimelineActor;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStateTransition;
use BackedEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Merges heterogeneous experiment events into one chronological stream where
 * human, agent and system activity sit side by side.
 *
 * Kanwas-inspired sprint — "human and agent on one shared timeline". Pure
 * read-side aggregation: no persistence of its own.
 */
final class UnifiedTimelineService
{
    /** @var list<string> */
    public const KINDS = ['transition', 'ai_run', 'approval', 'sandbox_file'];

    /**
     * @param  string|null  $kind  Restrict to a single kind (see KINDS), or null for all.
     * @return Collection<int, TimelineEntry>
     */
    public function build(Experiment $experiment, int $limit = 200, ?string $kind = null): Collection
    {
        $limit = max(1, min($limit, 500));
        $perSource = max(50, $limit);

        /** @var Collection<int, TimelineEntry> $entries */
        $entries = collect();

        if ($kind === null || $kind === 'transition') {
            $entries = $entries->concat($this->transitions($experiment, $perSource));
        }
        if ($kind === null || $kind === 'ai_run') {
            $entries = $entries->concat($this->aiRuns($experiment, $perSource));
        }
        if ($kind === null || $kind === 'approval') {
            $entries = $entries->concat($this->approvals($experiment, $perSource));
        }
        if ($kind === null || $kind === 'sandbox_file') {
            $entries = $entries->concat($this->sandboxFiles($experiment, $perSource));
        }

        return $entries
            ->sortByDesc(fn (TimelineEntry $e) => $e->occurredAt->getPreciseTimestamp())
            ->values()
            ->take($limit);
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private function transitions(Experiment $experiment, int $limit): Collection
    {
        return ExperimentStateTransition::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (ExperimentStateTransition $t) => new TimelineEntry(
                id: 'transition:'.$t->id,
                kind: 'transition',
                actor: $t->actor_id !== null ? TimelineActor::Human : TimelineActor::System,
                icon: 'fa-solid fa-arrow-right-arrow-left',
                title: 'State → '.($t->to_state ?? 'unknown'),
                summary: $t->from_state
                    ? 'from '.$t->from_state.($t->reason ? ' · '.$t->reason : '')
                    : $t->reason,
                occurredAt: $this->toCarbon($t->created_at),
                metadata: ['from' => $t->from_state, 'to' => $t->to_state],
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private function aiRuns(Experiment $experiment, int $limit): Collection
    {
        return AiRun::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AiRun $r) => new TimelineEntry(
                id: 'ai_run:'.$r->id,
                kind: 'ai_run',
                actor: TimelineActor::Agent,
                icon: 'fa-solid fa-robot',
                title: 'AI run: '.($r->purpose ?? 'execution'),
                summary: trim(($r->provider ? $r->provider.'/' : '').($r->model ?? '').' · '.($r->status ?? '')),
                occurredAt: $this->toCarbon($r->created_at),
                metadata: ['model' => $r->model, 'cost_credits' => $r->cost_credits],
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private function approvals(Experiment $experiment, int $limit): Collection
    {
        return ApprovalRequest::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (ApprovalRequest $a) => new TimelineEntry(
                id: 'approval:'.$a->id,
                kind: 'approval',
                actor: TimelineActor::Human,
                icon: 'fa-solid fa-user-check',
                title: 'Approval '.($this->enumValue($a->status) ?? 'pending'),
                summary: $a->rejection_reason ?: $a->reviewer_notes,
                occurredAt: $this->toCarbon($a->created_at),
                metadata: ['status' => $this->enumValue($a->status)],
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private function sandboxFiles(Experiment $experiment, int $limit): Collection
    {
        return SandboxFileActivity::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->orderByDesc('captured_at')
            ->limit($limit)
            ->get()
            ->map(fn (SandboxFileActivity $f) => new TimelineEntry(
                id: 'sandbox_file:'.$f->id,
                kind: 'sandbox_file',
                actor: TimelineActor::Agent,
                icon: 'fa-solid fa-file-lines',
                title: 'Sandbox file: '.$f->path,
                summary: $f->size_bytes !== null ? $f->size_bytes.' bytes' : null,
                occurredAt: $this->toCarbon($f->captured_at),
                metadata: ['path' => $f->path, 'operation' => $f->operation],
            ));
    }

    /**
     * Normalize a heterogeneous timestamp attribute to a single Carbon type.
     */
    private function toCarbon(\DateTimeInterface|string|null $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        return Carbon::parse($value);
    }

    /**
     * Resolve a backed enum (or plain string) attribute to its string value.
     */
    private function enumValue(BackedEnum|string|null $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $value;
    }
}
