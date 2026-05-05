<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Signal;

use App\Domain\Outbound\Actions\ReplyToEmailSignalAction;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('read')]
class EmailReplyTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'email_reply';

    protected string $description = 'Reply to an email-sourced signal, preserving the email thread '
        .'(sets In-Reply-To and References headers automatically). '
        .'Requires a team SMTP connector configured in Settings → Connectors → Email. '
        .'Only works for signals with source_type=email.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'signal_id' => $schema->string()
                ->description('UUID of the email signal to reply to (must have source_type=email)')
                ->required(),
            'body' => $schema->string()
                ->description('Reply body (plain text or HTML)')
                ->required(),
            'auto_send' => $schema->boolean()
                ->description('If true, send immediately. If false (default), creates an approved OutboundProposal for review.')
                ->default(false),
        ];
    }

    public function handle(Request $request): Response
    {
        $signalId = $request->get('signal_id');
        $body = $request->get('body');

        if (! $signalId) {
            return $this->invalidArgumentError('signal_id is required');
        }

        if (empty($body)) {
            return $this->invalidArgumentError('body is required');
        }

        $teamId = auth()->user()?->current_team_id
            ?? (app()->bound('mcp.team_id') ? app('mcp.team_id') : null);
        if (! $teamId) {
            return $this->permissionDeniedError('No active team context. Ensure you are authenticated with a team.');
        }

        try {
            /** @var OutboundProposal $proposal */
            $proposal = app(ReplyToEmailSignalAction::class)->execute(
                signalId: $signalId,
                body: $body,
                teamId: $teamId,
                autoSend: (bool) $request->get('auto_send', false),
            );

            $sent = $request->get('auto_send', false);

            return Response::text(json_encode([
                'success' => true,
                'proposal_id' => $proposal->id,
                'status' => $proposal->status->value,
                'sent' => $sent,
                'message' => $sent
                    ? 'Reply sent successfully.'
                    : 'Reply queued as OutboundProposal (approved, pending send).',
            ]));
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
