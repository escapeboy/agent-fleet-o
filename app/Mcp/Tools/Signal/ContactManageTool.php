<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Services\ContactResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ContactManageTool extends Tool
{
    protected string $name = 'contact_manage';

    protected string $description = 'Manage cross-channel contact identities. List, show details, merge two contacts, or link/unlink channels.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: list | get | merge | unlink_channel')
                ->enum(['list', 'get', 'merge', 'unlink_channel'])
                ->required(),
            'contact_id' => $schema->string()
                ->description('Contact identity UUID (required for get/merge/unlink_channel)'),
            'source_contact_id' => $schema->string()
                ->description('Source contact UUID to merge INTO contact_id (required for merge — source is deleted after merge)'),
            'channel_id' => $schema->string()
                ->description('Channel UUID to unlink (required for unlink_channel)'),
            'search' => $schema->string()
                ->description('Search term (name, email, phone, sender ID)'),
            'channel_filter' => $schema->string()
                ->description('Filter by channel: telegram | whatsapp | discord | slack | matrix'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $teamId = $user?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $action = $request->get('action', 'list');

        if ($action === 'list') {
            $query = ContactIdentity::query()
                ->withCount('channels')
                ->with('channels')
                ->orderByDesc('updated_at')
                ->limit(50);

            if ($search = $request->get('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('display_name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%")
                        ->orWhere('phone', 'ilike', "%{$search}%")
                        ->orWhereHas('channels', fn ($cq) => $cq
                            ->where('external_id', 'ilike', "%{$search}%")
                            ->orWhere('external_username', 'ilike', "%{$search}%"));
                });
            }

            if ($channel = $request->get('channel_filter')) {
                $query->whereHas('channels', fn ($cq) => $cq->where('channel', $channel));
            }

            $contacts = $query->get()->map(fn ($c) => [
                'id' => $c->id,
                'display_name' => $c->display_name,
                'email' => $c->email,
                'phone' => $c->phone,
                'channels_count' => $c->channels_count,
                'channels' => $c->channels->map(fn ($ch) => [
                    'channel' => $ch->channel,
                    'external_id' => $ch->external_id,
                    'external_username' => $ch->external_username,
                ])->values(),
                'updated_at' => $c->updated_at->toIso8601String(),
            ]);

            return Response::text(json_encode(['contacts' => $contacts, 'total' => $contacts->count()]));
        }

        if ($action === 'get') {
            $contactId = $request->get('contact_id');
            if (! $contactId) {
                return Response::error('contact_id is required for get action.');
            }

            $contact = ContactIdentity::where('team_id', $teamId)->with('channels')->findOrFail($contactId);

            return Response::text(json_encode([
                'id' => $contact->id,
                'display_name' => $contact->display_name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'metadata' => $contact->metadata,
                'channels' => $contact->channels->map(fn ($ch) => [
                    'id' => $ch->id,
                    'channel' => $ch->channel,
                    'external_id' => $ch->external_id,
                    'external_username' => $ch->external_username,
                    'verified' => $ch->verified,
                ])->values(),
                'created_at' => $contact->created_at->toIso8601String(),
                'updated_at' => $contact->updated_at->toIso8601String(),
            ]));
        }

        if ($action === 'merge') {
            $contactId = $request->get('contact_id');
            $sourceId = $request->get('source_contact_id');

            if (! $contactId || ! $sourceId) {
                return Response::error('contact_id and source_contact_id are both required for merge action.');
            }

            if ($contactId === $sourceId) {
                return Response::error('Cannot merge a contact with itself.');
            }

            $target = ContactIdentity::where('team_id', $teamId)->findOrFail($contactId);
            $source = ContactIdentity::where('team_id', $teamId)->findOrFail($sourceId);

            app(ContactResolver::class)->merge($source, $target);

            return Response::text("Contact {$sourceId} merged into {$contactId}. Source contact deleted.");
        }

        if ($action === 'unlink_channel') {
            $contactId = $request->get('contact_id');
            $channelId = $request->get('channel_id');

            if (! $contactId || ! $channelId) {
                return Response::error('contact_id and channel_id are both required for unlink_channel action.');
            }

            $contact = ContactIdentity::where('team_id', $teamId)->findOrFail($contactId);
            $channel = $contact->channels()->findOrFail($channelId);
            $channel->delete();

            return Response::text("Channel {$channelId} unlinked from contact {$contactId}.");
        }

        return Response::error("Unknown action: {$action}");
    }
}
