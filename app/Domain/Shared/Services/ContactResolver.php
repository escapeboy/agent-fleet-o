<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Models\ContactChannel;
use App\Domain\Shared\Models\ContactIdentity;

class ContactResolver
{
    /**
     * Resolve or create a ContactIdentity for the given channel sender.
     *
     * Resolution order:
     * 1. Exact match on (team_id, channel, external_id) in contact_channels
     * 2. Phone normalization match for WhatsApp/Signal/Matrix (E.164)
     * 3. Create a new ContactIdentity + ContactChannel pair
     *
     * @param  array{name?: string, phone?: string, email?: string}  $hints
     */
    public function resolveOrCreate(
        string $teamId,
        string $channel,
        string $externalId,
        array $hints = [],
    ): ContactIdentity {
        // 1. Exact channel match
        $contactChannel = ContactChannel::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->first();

        if ($contactChannel) {
            return $contactChannel->identity;
        }

        // 2. Phone normalization match (WhatsApp external_id is often the phone number)
        if (isset($hints['phone']) && $hints['phone'] !== '') {
            $normalized = $this->normalizePhone($hints['phone']);

            if ($normalized) {
                $existing = ContactIdentity::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->where('phone', $normalized)
                    ->first();

                if ($existing) {
                    // Attach this channel to the existing identity
                    $this->attachChannel($existing, $teamId, $channel, $externalId, $hints);

                    return $existing;
                }
            }
        }

        // 3. Create new identity + channel
        $phone = isset($hints['phone']) ? $this->normalizePhone($hints['phone']) : null;

        $identity = ContactIdentity::create([
            'team_id' => $teamId,
            'display_name' => $hints['name'] ?? null,
            'email' => $hints['email'] ?? null,
            'phone' => $phone,
        ]);

        $this->attachChannel($identity, $teamId, $channel, $externalId, $hints);

        return $identity;
    }

    /**
     * Merge two ContactIdentity records: move all channels from $source to $target, then delete $source.
     */
    public function merge(ContactIdentity $source, ContactIdentity $target): void
    {
        if ($source->team_id !== $target->team_id) {
            throw new \InvalidArgumentException('Cannot merge contacts from different teams.');
        }

        ContactChannel::withoutGlobalScopes()
            ->where('contact_identity_id', $source->id)
            ->update(['contact_identity_id' => $target->id]);

        // Merge metadata, signals FK (nullOnDelete handles signals automatically)
        $mergedMetadata = array_merge($source->metadata ?? [], $target->metadata ?? []);
        $target->update([
            'display_name' => $target->display_name ?? $source->display_name,
            'email' => $target->email ?? $source->email,
            'phone' => $target->phone ?? $source->phone,
            'metadata' => $mergedMetadata ?: null,
        ]);

        $source->delete();
    }

    /**
     * Normalize a phone number to E.164 format (+12125551234).
     * Returns null if the input doesn't look like a phone number.
     */
    private function normalizePhone(string $phone): ?string
    {
        // Strip everything except digits and leading +
        $stripped = preg_replace('/[^\d+]/', '', $phone) ?? '';

        if ($stripped === '') {
            return null;
        }

        // Already E.164
        if (str_starts_with($stripped, '+')) {
            return strlen($stripped) >= 8 ? $stripped : null;
        }

        // 00-prefix international format (e.g. 0049...)
        if (str_starts_with($stripped, '00')) {
            $stripped = '+'.substr($stripped, 2);

            return strlen($stripped) >= 8 ? $stripped : null;
        }

        // Bare digits — assume international without + (e.g. WhatsApp sends "12125551234")
        if (strlen($stripped) >= 10) {
            return '+'.$stripped;
        }

        return null;
    }

    /**
     * @param  array{name?: string, phone?: string, email?: string}  $hints
     */
    private function attachChannel(
        ContactIdentity $identity,
        string $teamId,
        string $channel,
        string $externalId,
        array $hints,
    ): void {
        ContactChannel::withoutGlobalScopes()->updateOrCreate(
            ['team_id' => $teamId, 'channel' => $channel, 'external_id' => $externalId],
            [
                'contact_identity_id' => $identity->id,
                'external_username' => $hints['name'] ?? null,
            ],
        );
    }
}
