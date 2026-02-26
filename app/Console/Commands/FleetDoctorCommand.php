<?php

namespace App\Console\Commands;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Domain\Telegram\Models\TelegramBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Laravel\Cashier\Cashier;

class FleetDoctorCommand extends Command
{
    protected $signature = 'fleet:doctor {--fix : Attempt to auto-fix simple issues}';

    protected $description = 'Run system health diagnostics and flag configuration problems';

    private int $warnings = 0;

    private int $criticals = 0;

    private int $infos = 0;

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=cyan>🦞 FleetQ Doctor</>');
        $this->line('');

        $this->checkRedis();
        $this->checkPostgres();
        $this->checkHorizon();
        $this->checkLlmKeys();
        $this->checkStripeKeys();
        $this->checkLlmPricing();
        $this->checkStaleExperiments();
        $this->checkQueueDepths();
        $this->checkS3Disk();
        $this->checkBudgetAlerts();

        $this->line('');

        $summary = "Summary: {$this->criticals} critical";
        if ($this->criticals > 0) {
            $summary = "<fg=red>{$summary}</>";
        }

        $warnText = ", {$this->warnings} warning(s), {$this->infos} info";
        $this->line($summary.$warnText);

        if ($this->option('fix')) {
            $this->line('');
            $this->line('<fg=yellow>Running auto-fixes...</>');
            $this->fixStaleExperiments();
        } elseif ($this->warnings > 0 || $this->criticals > 0) {
            $this->line('');
            $this->line('<fg=gray>Run `php artisan fleet:doctor --fix` to auto-fix simple issues.</>');
        }

        $this->line('');

        return $this->criticals > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function checkRedis(): void
    {
        try {
            Redis::ping();
            $host = config('database.redis.default.host', '127.0.0.1');
            $port = config('database.redis.default.port', 6379);
            $db = config('database.redis.default.database', 0);
            $this->ok("Redis: Connected ({$host}:{$port}, DB {$db})");
        } catch (\Throwable $e) {
            $this->critical('Redis: Connection failed — '.$e->getMessage());
        }
    }

    private function checkPostgres(): void
    {
        try {
            $version = DB::selectOne('SELECT version()');
            $versionStr = $version ? explode(' ', $version->version)[0].' '.explode(' ', $version->version)[1] : 'unknown';
            $this->ok("PostgreSQL: Connected ({$versionStr})");
        } catch (\Throwable $e) {
            $this->critical('PostgreSQL: Connection failed — '.$e->getMessage());
        }
    }

    private function checkHorizon(): void
    {
        try {
            $status = Redis::get('horizon:status');
            if ($status && $status === 'running') {
                $this->ok('Horizon: Running');
            } else {
                $this->warnLine('Horizon: Not running — queued jobs will not be processed');
            }
        } catch (\Throwable) {
            $this->warnLine('Horizon: Could not determine status (Redis unavailable)');
        }
    }

    private function checkLlmKeys(): void
    {
        $hasKey = config('prism.providers.anthropic.api_key') ||
            config('prism.providers.openai.api_key') ||
            config('prism.providers.google.api_key') ||
            env('ANTHROPIC_API_KEY') ||
            env('OPENAI_API_KEY') ||
            env('GOOGLE_AI_API_KEY');

        if ($hasKey) {
            $this->ok('LLM API key: At least one configured');
        } else {
            $this->warnLine('LLM API key: No provider keys set — AI features will not work');
        }
    }

    private function checkStripeKeys(): void
    {
        if (! class_exists(Cashier::class)) {
            // Not a cloud edition — skip Stripe checks
            return;
        }

        $key = config('cashier.key');
        $secret = config('cashier.secret');
        $webhookSecret = config('cashier.webhook.secret');

        if ($key && $secret) {
            $this->ok('Stripe: Keys configured');
        } else {
            $this->warnLine('Stripe: STRIPE_KEY or STRIPE_SECRET not set — billing will not work');
        }

        if (! $webhookSecret) {
            $this->warnLine('Stripe: STRIPE_WEBHOOK_SECRET not set — webhook validation will fail');
        }
    }

    private function checkLlmPricing(): void
    {
        $providers = config('llm_pricing.providers', []);
        $total = 0;
        foreach ($providers as $models) {
            $total += count($models);
        }

        if ($total > 0) {
            $this->ok("LLM pricing: {$total} model(s) configured with pricing");
        } else {
            $this->infoLine('LLM pricing: No model pricing configured (costs will not be tracked)');
        }
    }

    private function checkStaleExperiments(): void
    {
        try {
            $activeStatuses = array_map(
                fn (ExperimentStatus $s) => $s->value,
                array_filter(
                    ExperimentStatus::cases(),
                    fn (ExperimentStatus $s) => $s->isActive() && ! $s->isFailed(),
                ),
            );

            $stale = Experiment::withoutGlobalScopes()
                ->whereIn('status', $activeStatuses)
                ->where('updated_at', '<', now()->subHours(24))
                ->with('team')
                ->get();

            if ($stale->isEmpty()) {
                $this->ok('Stale experiments: None');
            } else {
                $this->warnLine("Stale experiments: {$stale->count()} experiment(s) stuck in active state for >24h");
                foreach ($stale as $exp) {
                    $this->line("    └─ {$exp->id} ({$exp->team?->name}) — stuck since {$exp->updated_at->toDateTimeString()}");
                }
            }
        } catch (\Throwable $e) {
            $this->infoLine('Stale experiments: Could not check — '.$e->getMessage());
        }
    }

    private function checkQueueDepths(): void
    {
        $queues = ['critical', 'ai-calls', 'experiments', 'outbound', 'metrics', 'default'];
        $overloaded = [];

        try {
            foreach ($queues as $queue) {
                $size = Redis::llen('queues:'.$queue);
                if ($size > 1000) {
                    $overloaded[] = "{$queue} ({$size})";
                }
            }

            if (empty($overloaded)) {
                $this->ok('Queue depths: All queues within normal range');
            } else {
                $this->warnLine('Queue depths: Overloaded queues — '.implode(', ', $overloaded));
            }
        } catch (\Throwable) {
            $this->infoLine('Queue depths: Could not check (Redis unavailable)');
        }
    }

    private function checkS3Disk(): void
    {
        $disk = config('filesystems.default', 'local');
        if ($disk !== 's3') {
            $this->infoLine("Storage disk: Using '{$disk}' (not S3)");

            return;
        }

        try {
            $testKey = '_fleet_doctor_test_'.uniqid();
            Storage::put($testKey, 'test');
            Storage::delete($testKey);
            $this->ok('S3 storage: Writable');
        } catch (\Throwable $e) {
            $this->warnLine('S3 storage: Write test failed — '.$e->getMessage());
        }
    }

    private function checkBudgetAlerts(): void
    {
        try {
            $teamsOverBudget = Team::withoutGlobalScopes()
                ->whereNotNull('credit_balance')
                ->whereRaw('credit_balance < credit_cap * 0.10')
                ->whereColumn('credit_balance', '<', 'credit_cap')
                ->where('credit_cap', '>', 0)
                ->get();

            if ($teamsOverBudget->isEmpty()) {
                // Also show info about Telegram bots without binding gate (Phase 1 integration)
                $this->checkTelegramBots();
            } else {
                $this->infoLine("Budget: {$teamsOverBudget->count()} team(s) at <10% credit remaining");
                foreach ($teamsOverBudget as $team) {
                    $this->line("    └─ {$team->name}");
                }
                $this->checkTelegramBots();
            }
        } catch (\Throwable) {
            // Budget columns may not exist in community edition
            $this->checkTelegramBots();
        }
    }

    private function checkTelegramBots(): void
    {
        try {
            $botCount = TelegramBot::withoutGlobalScopes()->where('status', 'active')->count();
            if ($botCount === 0) {
                $this->ok('Telegram bots: None configured');
            } else {
                $this->ok("Telegram bots: {$botCount} active bot(s) configured");
            }
        } catch (\Throwable) {
            // TelegramBot table may not exist
        }
    }

    private function fixStaleExperiments(): void
    {
        $this->line('  Checking for stale experiments to kill...');

        try {
            $activeStatuses = array_map(
                fn (ExperimentStatus $s) => $s->value,
                array_filter(
                    ExperimentStatus::cases(),
                    fn (ExperimentStatus $s) => $s->isActive() && ! $s->isFailed(),
                ),
            );

            $stale = Experiment::withoutGlobalScopes()
                ->whereIn('status', $activeStatuses)
                ->where('updated_at', '<', now()->subHours(24))
                ->get();

            foreach ($stale as $exp) {
                $exp->update([
                    'status' => ExperimentStatus::Killed->value,
                    'killed_at' => now(),
                ]);
                $this->line("  ✓ Killed experiment {$exp->id}");
            }

            if ($stale->isEmpty()) {
                $this->line('  No stale experiments to fix.');
            }
        } catch (\Throwable $e) {
            $this->line("  Could not fix stale experiments: {$e->getMessage()}");
        }
    }

    private function ok(string $message): void
    {
        $this->line("<fg=green>✅ {$message}</>");
    }

    private function warnLine(string $message): void
    {
        $this->warnings++;
        $this->line("<fg=yellow>⚠️  {$message}</>");
    }

    private function critical(string $message): void
    {
        $this->criticals++;
        $this->line("<fg=red>🔴 {$message}</>");
    }

    private function infoLine(string $message): void
    {
        $this->infos++;
        $this->line("<fg=blue>ℹ️  {$message}</>");
    }
}
