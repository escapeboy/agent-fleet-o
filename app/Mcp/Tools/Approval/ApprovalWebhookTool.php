<?php

namespace App\Mcp\Tools\Approval;

use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ApprovalWebhookTool extends Tool
{
    protected string $name = 'approval_webhook_config';

    protected string $description = 'Configure or inspect webhook callback settings for an approval request. When callback_url is set, a signed POST is fired to that URL after approval/rejection.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'approval_id' => $schema->string()
                ->description('The approval request UUID')
                ->required(),
            'callback_url' => $schema->string()
                ->description('HTTPS URL to POST the decision payload to (set to null to clear)'),
            'callback_secret' => $schema->string()
                ->description('Secret used to sign the payload via HMAC-SHA256 (X-Signature-SHA256 header)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'approval_id' => 'required|string',
            'callback_url' => 'nullable|url',
            'callback_secret' => 'nullable|string|max:255',
        ]);

        $approval = ApprovalRequest::find($validated['approval_id']);

        if (! $approval) {
            return Response::error('Approval request not found.');
        }

        // If no update fields provided, return current config
        if (! array_key_exists('callback_url', $validated) && ! array_key_exists('callback_secret', $validated)) {
            return Response::text(json_encode([
                'approval_id' => $approval->id,
                'callback_url' => $approval->callback_url,
                'callback_status' => $approval->callback_status,
                'callback_fired_at' => $approval->callback_fired_at?->toIso8601String(),
            ]));
        }

        $updates = [];
        if (array_key_exists('callback_url', $validated)) {
            $updates['callback_url'] = $validated['callback_url'];
        }
        if (array_key_exists('callback_secret', $validated)) {
            $updates['callback_secret'] = $validated['callback_secret'];
        }

        $approval->update($updates);

        return Response::text(json_encode([
            'success' => true,
            'approval_id' => $approval->id,
            'callback_url' => $approval->callback_url,
        ]));
    }
}
