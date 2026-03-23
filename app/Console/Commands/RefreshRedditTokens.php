<?php

namespace App\Console\Commands;

use App\Domain\Credential\Actions\RefreshRedditTokenAction;
use App\Domain\Credential\Models\Credential;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshRedditTokens extends Command
{
    protected $signature = 'credentials:refresh-reddit';

    protected $description = 'Refresh expiring Reddit session tokens stored in Credentials';

    public function __construct(
        private readonly RefreshRedditTokenAction $refreshAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $credentials = Credential::withoutGlobalScopes()
            ->where('credential_type', 'custom_kv')
            ->where('slug', 'like', '%reddit%')
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn ($c) => ! empty(($c->secret_data ?? [])['reddit_session']));

        if ($credentials->isEmpty()) {
            $this->info('No Reddit credentials found.');

            return self::SUCCESS;
        }

        foreach ($credentials as $credential) {
            /** @var Credential $credential */
            try {
                $this->refreshAction->execute($credential);
                $this->info("Refreshed: {$credential->getAttribute('name')}");
            } catch (\Throwable $e) {
                Log::error('RefreshRedditTokens: failed', [
                    'credential_id' => $credential->getKey(),
                    'error' => $e->getMessage(),
                ]);
                $this->error("{$credential->getAttribute('name')}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
