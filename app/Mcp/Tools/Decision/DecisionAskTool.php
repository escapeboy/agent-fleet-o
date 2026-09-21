<?php

namespace App\Mcp\Tools\Decision;

use App\Domain\Budget\Actions\ReserveBudgetAction;
use App\Domain\Budget\Actions\SettleBudgetAction;
use App\Domain\Decision\DTOs\Answer;
use App\Domain\Decision\Services\DecisionDriverResolver;
use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

/**
 * IsDestructive, like every other MCP tool here that executes and spends: the
 * call debits credits when it runs on the platform key, and a debit is not
 * reversible. It follows CrewExecuteTool and IntegrationExecuteTool, not the
 * read-only MultiModelConsensusTool, which only lists and inspects.
 */
#[IsDestructive]
#[AssistantTool('write')]
class DecisionAskTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'decision_ask';

    protected string $description = 'Put typed questions to a decision model and get one typed answer per question in a single call. Not a chat model: it returns structured values (choice, score, noul) with probabilities and a confidence, never prose, and answers every question in one round trip. Use it to judge or classify a state you can then branch on; use an LLM tool when you need generated text. Runs on the team\'s own key when one is configured, otherwise on the platform key for credits.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'state' => $schema->string()->description('The state to judge — the text or serialised data the questions are asked about')->required(),
            'questions' => $schema->object()->description('Map of question id => {type: choice|score|noul, instructions: string, criteria?: array}')->required(),
            'driver' => $schema->string()->description('Decision driver name; defaults to the configured default (jev)'),
            'min_confidence' => $schema->number()->description('Answers below this confidence are listed in low_confidence. A null confidence always counts as low.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $validated = $request->validate([
            'state' => 'required|string|max:100000',
            'questions' => 'required|array|min:1|max:32',
            'driver' => 'nullable|string|max:64',
            'min_confidence' => 'nullable|numeric|between:0,1',
        ]);

        // Team is the tenant itself, not a tenant-scoped model — no global scope
        // to bypass, and bypassing one here would trip the cross-tenant guard for
        // no reason. Matches how every other MCP tool loads it.
        $team = Team::find($teamId);
        if (! $team) {
            return $this->notFoundError('team');
        }

        try {
            $resolved = app(DecisionDriverResolver::class)->resolve($team, $validated['driver'] ?? null);
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgumentError($e->getMessage());
        } catch (Throwable $e) {
            return $this->failedPreconditionError($e->getMessage());
        }

        // Same reserve → call → settle as DecisionNodeExecutor and
        // ExecuteDecisionSkillAction. Without it this tool would spend the
        // platform key with no ledger entry, which is the one surface a user
        // can reach directly.
        $reservation = null;
        if ($resolved->isBillable()) {
            try {
                $reservation = app(ReserveBudgetAction::class)->execute(
                    userId: (string) (auth()->id() ?? ''),
                    teamId: (string) $teamId,
                    amount: $resolved->creditsPerCall,
                    description: "Decision call ({$resolved->name})",
                );
            } catch (Throwable $e) {
                return $this->failedPreconditionError($e->getMessage());
            }
        }

        try {
            $result = $resolved->driver->decide($validated['state'], $validated['questions']);
        } catch (Throwable $e) {
            // Release the whole reservation, and never let a settlement error
            // replace the driver error the caller needs to see.
            if ($reservation !== null) {
                try {
                    app(SettleBudgetAction::class)->execute($reservation, 0);
                } catch (Throwable) {
                    // Do not mask the original error.
                }
            }

            return $this->failedPreconditionError($e->getMessage());
        }

        app(SettleBudgetAction::class)->execute($reservation, $resolved->isBillable() ? $resolved->creditsPerCall : 0);

        $minConfidence = isset($validated['min_confidence']) ? (float) $validated['min_confidence'] : null;
        $answers = [];
        $lowConfidence = [];

        foreach ($result->answers as $id => $answer) {
            /** @var Answer $answer */
            $answers[$id] = $answer->toArray() + ['value' => $answer->value()];

            if ($minConfidence === null) {
                continue;
            }
            $confidence = $answer->confidence();
            if ($confidence === null || $confidence < $minConfidence) {
                $lowConfidence[] = (string) $id;
            }
        }

        return Response::json([
            'answers' => $answers,
            'model' => $result->model,
            'latency_ms' => $result->latencyMs,
            'input_tokens' => $result->inputTokens,
            'low_confidence' => $lowConfidence,
            'decided_by' => $resolved->source,
        ]);
    }
}
