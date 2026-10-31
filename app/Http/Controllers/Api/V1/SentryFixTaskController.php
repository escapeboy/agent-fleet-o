<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Signal\Actions\ClaimSentryFixTaskAction;
use App\Domain\Signal\Actions\RecordSentryFixTaskEventAction;
use App\Domain\Signal\Enums\SentryFixTaskState;
use App\Domain\Signal\Exceptions\InvalidSentryFixTaskTransitionException;
use App\Domain\Signal\Models\SentryFixTask;
use App\Domain\Signal\Services\SentryFixTierClassifier;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Work queue for an external Sentry fix worker. Requires a Sanctum token with
 * the `sentry-fixes` ability; every query is scoped to the token user's team.
 *
 * @group Sentry Fix Tasks
 */
class SentryFixTaskController extends Controller
{
    private const ABILITY = 'sentry-fixes';

    /**
     * Atomically claim the oldest queued task (critical first).
     *
     * @response 200 {"data": {"id": "uuid", "state": "claimed", "attempts": 1}}
     * @response 204
     */
    public function claim(Request $request, ClaimSentryFixTaskAction $claim): JsonResponse|Response
    {
        $this->authorizeAbility($request);

        $data = $request->validate([
            'worker' => ['required', 'string', 'max:255'],
            'exclude_repositories' => ['sometimes', 'array'],
            'exclude_repositories.*' => ['string', 'max:255'],
        ]);

        $task = $claim->execute(
            $this->teamId($request),
            $data['worker'],
            $data['exclude_repositories'] ?? [],
        );

        if ($task === null) {
            return response()->noContent();
        }

        return response()->json(['data' => $task->toApiArray()]);
    }

    /**
     * @response 200 {"data": [{"id": "uuid", "state": "approved"}]}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAbility($request);

        $request->validate([
            'state' => ['sometimes', 'string', 'max:255'],
            'repository' => ['sometimes', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = SentryFixTask::query()->where('team_id', $this->teamId($request));

        if ($request->filled('state')) {
            $states = array_filter(array_map('trim', explode(',', (string) $request->query('state'))));
            $invalid = array_diff($states, array_map(static fn (SentryFixTaskState $s): string => $s->value, SentryFixTaskState::cases()));
            if ($invalid !== []) {
                abort(422, 'Unknown state: '.implode(', ', $invalid));
            }
            $query->whereIn('state', $states);
        }
        if ($request->filled('repository')) {
            $query->where('repository', (string) $request->query('repository'));
        }

        $tasks = $query->orderBy('created_at')->limit((int) $request->query('limit', 100))->get();

        return response()->json(['data' => $tasks->map->toApiArray()->values()]);
    }

    /**
     * @response 200 {"data": {"id": "uuid", "state": "fixing"}}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorizeAbility($request);

        return response()->json(['data' => $this->findTask($request, $id)->toApiArray()]);
    }

    /**
     * Report a state change. Disallowed transitions return 422; `done` resolves the signal.
     *
     * @response 200 {"data": {"id": "uuid", "state": "pr_open"}}
     * @response 422 {"message": "Cannot transition sentry fix task from queued to done."}
     */
    public function event(Request $request, string $id, RecordSentryFixTaskEventAction $record): JsonResponse
    {
        $this->authorizeAbility($request);

        $data = $request->validate([
            'state' => ['required', 'string', Rule::enum(SentryFixTaskState::class)],
            'message' => ['required', 'string', 'max:2000'],
            'pr_url' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $task = $this->findTask($request, $id);

        try {
            $task = $record->execute(
                $task,
                SentryFixTaskState::from($data['state']),
                $data['message'],
                $data['pr_url'] ?? null,
            );
        } catch (InvalidSentryFixTaskTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $task->toApiArray()]);
    }

    /**
     * Compute the fix risk tier from the real diff and store it on the task.
     *
     * @response 200 {"data": {"tier": "t2"}}
     */
    public function classify(Request $request, string $id, SentryFixTierClassifier $classifier): JsonResponse
    {
        $this->authorizeAbility($request);

        $data = $request->validate([
            'files' => ['present', 'array'],
            'files.*' => ['string', 'max:1000'],
            'diff_lines' => ['required', 'integer', 'min:0'],
        ]);

        $task = $this->findTask($request, $id);
        $tier = $classifier->classify($data['files'], (int) $data['diff_lines']);

        $task->tier = $tier;
        $task->save();

        return response()->json(['data' => ['tier' => $tier->value]]);
    }

    private function authorizeAbility(Request $request): void
    {
        $token = $request->user()?->currentAccessToken();

        if ($token === null || ! $token->can(self::ABILITY)) {
            abort(403, 'Token lacks the '.self::ABILITY.' ability.');
        }
    }

    private function teamId(Request $request): string
    {
        $teamId = $request->user()->current_team_id;
        abort_if($teamId === null, 403, 'No active team.');

        return (string) $teamId;
    }

    private function findTask(Request $request, string $id): SentryFixTask
    {
        abort_unless(Str::isUuid($id), 404);

        return SentryFixTask::query()
            ->where('team_id', $this->teamId($request))
            ->where('id', $id)
            ->firstOrFail();
    }
}
