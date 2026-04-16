<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Signal\Actions\AddSignalCommentAction;
use App\Domain\Signal\Enums\CommentAuthorType;
use App\Domain\Signal\Enums\SignalStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Widget\Concerns\ResolvesWidgetAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BugReportConfirmController extends Controller
{
    use ResolvesWidgetAccess;

    public function __invoke(Request $request, string $signal, AddSignalCommentAction $action): JsonResponse
    {
        $validated = $request->validate([
            'team_public_key' => ['required', 'string'],
            'confirmed' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:10000'],
        ]);

        $team = $this->resolveTeam($validated['team_public_key']);
        $signalModel = $this->resolveBugReportSignal($team, $signal);

        $this->throttle('widget-bug-report-confirm:'.$signalModel->id, 5);

        $currentStatus = $signalModel->status instanceof SignalStatus
            ? $signalModel->status
            : SignalStatus::tryFrom((string) $signalModel->status);

        if ($currentStatus !== SignalStatus::Resolved) {
            return $this->withCors(response()->json([
                'error' => 'not_resolved',
                'message' => 'Confirmation is only allowed on resolved bug reports.',
            ], 422));
        }

        $confirmed = (bool) $validated['confirmed'];
        $note = isset($validated['comment']) ? $this->sanitizeBody($validated['comment']) : '';

        $result = DB::transaction(function () use ($action, $signalModel, $confirmed, $note) {
            $body = $confirmed
                ? trim('Reporter confirmed fix. '.$note)
                : trim('Reporter rejected fix — reopening. '.$note);

            if (! $confirmed) {
                $signalModel->forceFill(['status' => SignalStatus::Received->value])->save();
            }

            $comment = $action->execute(
                signal: $signalModel,
                body: $body,
                authorType: CommentAuthorType::Reporter,
            );

            return [
                'status' => $signalModel->fresh()->status,
                'comment_id' => $comment->id,
            ];
        });

        $status = $result['status'];
        if ($status instanceof SignalStatus) {
            $status = $status->value;
        }

        return $this->withCors(response()->json([
            'status' => $status,
            'comment_id' => $result['comment_id'],
            'confirmed' => $confirmed,
        ]));
    }
}
