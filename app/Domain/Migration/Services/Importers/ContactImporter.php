<?php

namespace App\Domain\Migration\Services\Importers;

use App\Domain\Shared\Models\ContactIdentity;

final class ContactImporter extends EntityImporter
{
    public function entityType(): string
    {
        return 'contact';
    }

    public function supportedAttributes(): array
    {
        return [
            'display_name' => 'Display name (e.g. "Jane Doe")',
            'email' => 'Primary email address',
            'phone' => 'Primary phone number (any format)',
            'metadata' => 'JSON metadata blob — all unmapped columns land here',
        ];
    }

    public function importRow(string $teamId, array $row, array $mapping, callable $onError): string
    {
        $attrs = ['team_id' => $teamId];
        $metadata = [];

        foreach ($row as $column => $value) {
            $target = $mapping[$column] ?? null;
            if ($target === null || $target === '') {
                if ($value !== '') {
                    $metadata[$column] = $value;
                }

                continue;
            }

            if (in_array($target, ['display_name', 'email', 'phone'], true)) {
                $attrs[$target] = $value === '' ? null : $value;
            } elseif ($target === 'metadata') {
                if ($value !== '') {
                    $metadata[$column] = $value;
                }
            }
        }

        if ($metadata !== []) {
            $attrs['metadata'] = $metadata;
        }

        $email = $attrs['email'] ?? null;
        $displayName = $attrs['display_name'] ?? null;

        if (($email === null || $email === '') && ($displayName === null || $displayName === '')) {
            $onError('row missing both email and display_name — skipped');

            return 'skipped';
        }

        try {
            if ($email !== null && $email !== '') {
                $existing = ContactIdentity::query()
                    ->where('team_id', $teamId)
                    ->where('email', $email)
                    ->first();
                if ($existing !== null) {
                    $update = $this->diffForUpdate($existing, $attrs);
                    if ($update === []) {
                        return 'skipped';
                    }
                    $existing->update($update);

                    return 'updated';
                }
            }

            ContactIdentity::create($attrs);

            return 'created';
        } catch (\Throwable $e) {
            $onError('row import failed: '.$e->getMessage());

            return 'failed';
        }
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function diffForUpdate(ContactIdentity $existing, array $incoming): array
    {
        $diff = [];
        foreach (['display_name', 'phone'] as $col) {
            if (! array_key_exists($col, $incoming)) {
                continue;
            }
            if ($existing->{$col} === null && $incoming[$col] !== null) {
                $diff[$col] = $incoming[$col];
            }
        }
        if (array_key_exists('metadata', $incoming)) {
            $existingMeta = $existing->metadata ?? [];
            $merged = array_merge($existingMeta, $incoming['metadata']);
            if ($merged !== $existingMeta) {
                $diff['metadata'] = $merged;
            }
        }

        return $diff;
    }
}
