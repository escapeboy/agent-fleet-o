<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Enums\CommentAuthorType;
use App\Domain\Signal\Events\SignalAssigned;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignSignalAction
{
    public function execute(
        Signal $signal,
        ?User $assignee,
        User $actor,
        ?string $reason = null,
    ): Signal {
        if ($assignee && $assignee->teams()->where('teams.id', $signal->team_id)->doesntExist()) {
            throw new \InvalidArgumentException('Assignee must be a member of the signal team.');
        }

        DB::transaction(function () use ($signal, $assignee, $actor, $reason) {
            $previousAssignee = $signal->assignedUser;

            $signal->update([
                'assigned_user_id' => $assignee?->id,
                'assigned_at' => $assignee ? now() : null,
            ]);

            if ($previousAssignee && $assignee && $previousAssignee->id !== $assignee->id) {
                SignalComment::create([
                    'team_id' => $signal->team_id,
                    'signal_id' => $signal->id,
                    'user_id' => null,
                    'author_type' => CommentAuthorType::Agent->value,
                    'body' => "Reassigned from {$previousAssignee->name} to {$assignee->name}.",
                    'widget_visible' => false,
                ]);
            }

            if ($reason) {
                SignalComment::create([
                    'team_id' => $signal->team_id,
                    'signal_id' => $signal->id,
                    'user_id' => $actor->id,
                    'author_type' => CommentAuthorType::Human->value,
                    'body' => $reason,
                    'widget_visible' => false,
                ]);
            }
        });

        $signal->refresh();

        if ($assignee) {
            SignalAssigned::dispatch($signal, $assignee, $actor, $reason);
        }

        return $signal;
    }
}
