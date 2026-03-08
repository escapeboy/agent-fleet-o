<?php

namespace App\Console\Commands;

use App\Domain\Credential\Models\Credential;
use App\Domain\Outbound\Models\OutboundConnectorConfig;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Tool\Models\TeamToolActivation;
use App\Domain\Tool\Models\Tool;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Infrastructure\Encryption\CredentialEncryption;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ReEncryptCredentials extends Command
{
    protected $signature = 'credentials:re-encrypt
        {--batch=50 : Number of records to process at a time}
        {--dry-run : Show what would be re-encrypted without making changes}';

    protected $description = 'Migrate credentials from APP_KEY encryption (v1) to per-team encryption (v2)';

    private int $reEncrypted = 0;

    private int $skipped = 0;

    private int $failed = 0;

    public function handle(CredentialEncryption $encryption): int
    {
        $dryRun = $this->option('dry-run');
        $batch = (int) $this->option('batch');

        if ($dryRun) {
            $this->components->warn('DRY RUN — no changes will be made.');
        }

        $this->ensureTeamKeys($dryRun);

        $models = [
            [Credential::class, 'secret_data'],
            [TeamProviderCredential::class, 'credentials'],
            [Tool::class, 'credentials'],
            [OutboundConnectorConfig::class, 'credentials'],
            [TeamToolActivation::class, 'credential_overrides'],
        ];

        foreach ($models as [$modelClass, $column]) {
            $this->reEncryptModel($modelClass, $column, $batch, $dryRun, $encryption);
        }

        // Migrate PHP-serialized string fields (stored via the old `encrypted` cast).
        // These use a different decryption path because Eloquent's `encrypted` cast
        // PHP-serializes values, while CredentialEncryption expects JSON-encoded data.
        $stringModels = [
            [TelegramBot::class, 'bot_token'],
            [WebhookEndpoint::class, 'secret'],
        ];

        foreach ($stringModels as [$modelClass, $column]) {
            $this->reEncryptStringModel($modelClass, $column, $batch, $dryRun, $encryption);
        }

        $this->newLine();
        $this->components->info("Re-encryption complete: {$this->reEncrypted} migrated, {$this->skipped} already v2, {$this->failed} failed.");

        return self::SUCCESS;
    }

    private function ensureTeamKeys(bool $dryRun): void
    {
        $teamsWithoutKey = Team::withoutGlobalScopes()
            ->whereNull('credential_key')
            ->count();

        if ($teamsWithoutKey === 0) {
            $this->components->info('All teams have credential keys.');

            return;
        }

        $this->components->info("Generating keys for {$teamsWithoutKey} team(s)...");

        if ($dryRun) {
            return;
        }

        Team::withoutGlobalScopes()
            ->whereNull('credential_key')
            ->each(function (Team $team) {
                $team->update(['credential_key' => CredentialEncryption::generateKey()]);
            });

        $this->components->info('Team keys generated.');
    }

    private function reEncryptModel(
        string $modelClass,
        string $column,
        int $batch,
        bool $dryRun,
        CredentialEncryption $encryption,
    ): void {
        $shortName = class_basename($modelClass);
        $this->components->info("Processing {$shortName}.{$column}...");

        /** @var Model $modelClass */
        $query = $modelClass::withoutGlobalScopes()
            ->whereNotNull($column)
            ->where($column, '!=', '');

        $total = $query->count();

        if ($total === 0) {
            $this->components->info('  No records to process.');

            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($batch, function (Collection $records) use ($column, $dryRun, $encryption, $bar) {
            foreach ($records as $record) {
                try {
                    $rawValue = $record->getRawOriginal($column);

                    if (! $rawValue || $rawValue === '') {
                        $this->skipped++;
                        $bar->advance();

                        continue;
                    }

                    // Check if already v2 format
                    if ($this->isV2Format($rawValue)) {
                        $this->skipped++;
                        $bar->advance();

                        continue;
                    }

                    if ($dryRun) {
                        $this->reEncrypted++;
                        $bar->advance();

                        continue;
                    }

                    $teamId = $record->team_id;

                    // Decrypt with v1 (APP_KEY)
                    $plaintext = $encryption->decrypt($rawValue, $teamId);

                    if ($plaintext === null) {
                        $this->skipped++;
                        $bar->advance();

                        continue;
                    }

                    // Re-encrypt with v2 (team key)
                    $encrypted = $encryption->encrypt($plaintext, $teamId);

                    // Direct DB update to avoid Eloquent cast interference
                    $record->getConnection()
                        ->table($record->getTable())
                        ->where($record->getKeyName(), $record->getKey())
                        ->update([$column => $encrypted]);

                    $this->reEncrypted++;
                } catch (\Throwable $e) {
                    $this->failed++;
                    $this->components->error("  Failed {$record->getKey()}: {$e->getMessage()}");
                }

                $bar->advance();
            }

            // Clear key cache periodically to limit memory usage
            $encryption->clearKeyCache();
        });

        $bar->finish();
        $this->newLine();
    }

    /**
     * Re-encrypt a string column that was previously stored via Eloquent's `encrypted` cast
     * (PHP-serialized). Migrates to the TeamEncryptedString v2 format ({"_s":"..."} under
     * the team's DEK), which is what the new TeamEncryptedString cast expects.
     */
    private function reEncryptStringModel(
        string $modelClass,
        string $column,
        int $batch,
        bool $dryRun,
        CredentialEncryption $encryption,
    ): void {
        $shortName = class_basename($modelClass);
        $this->components->info("Processing {$shortName}.{$column} (string field)...");

        /** @var Model $modelClass */
        $query = $modelClass::withoutGlobalScopes()
            ->whereNotNull($column)
            ->where($column, '!=', '');

        $total = $query->count();

        if ($total === 0) {
            $this->components->info('  No records to process.');

            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($batch, function (Collection $records) use ($column, $dryRun, $encryption, $bar) {
            foreach ($records as $record) {
                try {
                    $rawValue = $record->getRawOriginal($column);

                    if (! $rawValue || $rawValue === '') {
                        $this->skipped++;
                        $bar->advance();

                        continue;
                    }

                    // Check if already v2 format ({"_s":"..."} wrapped in team-DEK envelope)
                    if ($this->isV2Format($rawValue)) {
                        $this->skipped++;
                        $bar->advance();

                        continue;
                    }

                    if ($dryRun) {
                        $this->reEncrypted++;
                        $bar->advance();

                        continue;
                    }

                    $teamId = $record->team_id;

                    // Decrypt with PHP-unserialization (legacy `encrypted` cast format)
                    try {
                        $plaintext = app('encrypter')->decrypt($rawValue);
                    } catch (\Throwable) {
                        // Not PHP-serialized — try APP_KEY JSON path as a last resort
                        try {
                            $json = app('encrypter')->decrypt($rawValue, false);
                            $decoded = json_decode($json, true);
                            $plaintext = is_array($decoded) ? ($decoded['_s'] ?? null) : $decoded;
                        } catch (\Throwable) {
                            $this->skipped++;
                            $bar->advance();

                            continue;
                        }
                    }

                    if ($plaintext === null) {
                        $this->skipped++;
                        $bar->advance();

                        continue;
                    }

                    // Re-encrypt in TeamEncryptedString v2 format
                    $encrypted = $encryption->encrypt(['_s' => (string) $plaintext], $teamId);

                    // Direct DB update to avoid Eloquent cast interference
                    $record->getConnection()
                        ->table($record->getTable())
                        ->where($record->getKeyName(), $record->getKey())
                        ->update([$column => $encrypted]);

                    $this->reEncrypted++;
                } catch (\Throwable $e) {
                    $this->failed++;
                    $this->components->error("  Failed {$record->getKey()}: {$e->getMessage()}");
                }

                $bar->advance();
            }

            $encryption->clearKeyCache();
        });

        $bar->finish();
        $this->newLine();
    }

    private function isV2Format(string $stored): bool
    {
        $decoded = base64_decode($stored, true);
        if ($decoded === false) {
            return false;
        }

        $envelope = @json_decode($decoded, true);

        return is_array($envelope) && ($envelope['v'] ?? 0) === 2;
    }
}
