<?php

namespace App\Console\Commands;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

class InstallCommand extends Command
{
    protected $signature = 'app:install {--force : Skip confirmation prompts}';

    protected $description = 'Set up Agent Fleet Community Edition';

    public function handle(): int
    {
        $this->components->info('Agent Fleet — Community Edition Setup');
        $this->newLine();

        // Step 1: Check requirements
        $this->components->twoColumnDetail('Step 1/7', 'System Requirements');

        if (! $this->checkRequirements()) {
            return self::FAILURE;
        }

        $this->newLine();

        // Step 2: Database
        $this->components->twoColumnDetail('Step 2/7', 'Database');

        $alreadyInstalled = false;
        try {
            $alreadyInstalled = Team::query()->exists();
        } catch (\Exception) {
            // Table doesn't exist yet — fresh install
        }

        if ($alreadyInstalled) {
            $this->components->warn('Agent Fleet is already installed.');

            if (! $this->option('force') && ! $this->components->confirm('Re-run migrations anyway?', false)) {
                $this->components->info('Skipping database setup.');
            } else {
                Artisan::call('migrate', ['--force' => true], $this->output);
            }
        } else {
            Artisan::call('migrate', ['--force' => true], $this->output);
        }

        $this->newLine();

        // Step 3: Admin account
        $this->components->twoColumnDetail('Step 3/7', 'Admin Account');

        $usersExist = false;
        try {
            $usersExist = User::query()->exists();
        } catch (\Exception) {
            // Table may not exist yet
        }

        if ($usersExist && ! $this->option('force')) {
            $this->components->info('Users already exist. Skipping admin creation.');
        } else {
            $this->createAdminAccount();
        }

        $this->newLine();

        // Step 4: LLM Provider
        $this->components->twoColumnDetail('Step 4/7', 'LLM Provider (optional)');

        if ($this->option('force')) {
            $this->components->info('Skipping LLM configuration (--force). Configure later in Settings or .env.');
        } else {
            $this->configureLlmProvider();
        }

        $this->newLine();

        // Step 5: Local Agents
        $this->components->twoColumnDetail('Step 5/7', 'Local Agents (optional)');
        $this->detectLocalAgents();

        $this->newLine();

        // Step 6: Seed default skills & agents
        $this->components->twoColumnDetail('Step 6/7', 'Default Skills & Agents');
        $this->components->info('Seeding default skills and agents...');
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\SkillAndAgentSeeder', '--force' => true], $this->output);

        $this->newLine();

        // Step 7: Seed popular tools
        $this->components->twoColumnDetail('Step 7/7', 'Popular Tools');
        $this->components->info('Seeding popular tools (disabled by default)...');
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PopularToolsSeeder', '--force' => true], $this->output);

        $this->newLine();

        // Clear caches
        Artisan::call('config:clear', [], $this->output);
        Artisan::call('view:clear', [], $this->output);

        $this->newLine();
        $this->components->info('Installation complete!');
        $this->components->info('Visit ' . config('app.url') . ' to get started.');

        return self::SUCCESS;
    }

    private function checkRequirements(): bool
    {
        $ok = true;

        // PHP version
        if (version_compare(PHP_VERSION, '8.4.0', '>=')) {
            $this->components->twoColumnDetail('PHP ' . PHP_VERSION, '<fg=green>OK</>');
        } else {
            $this->components->twoColumnDetail('PHP ' . PHP_VERSION, '<fg=red>FAIL — PHP 8.4+ required</>');
            $ok = false;
        }

        // PostgreSQL
        try {
            DB::connection()->getPdo();
            $version = DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $this->components->twoColumnDetail('PostgreSQL ' . $version, '<fg=green>OK</>');
        } catch (\Exception $e) {
            $this->components->twoColumnDetail('PostgreSQL', '<fg=red>FAIL — ' . $e->getMessage() . '</>');
            $ok = false;
        }

        // Redis
        try {
            Redis::connection()->ping();
            $this->components->twoColumnDetail('Redis', '<fg=green>OK</>');
        } catch (\Exception $e) {
            $this->components->twoColumnDetail('Redis', '<fg=red>FAIL — ' . $e->getMessage() . '</>');
            $ok = false;
        }

        // APP_KEY
        if (empty(config('app.key'))) {
            $this->components->warn('APP_KEY is missing. Generating...');
            Artisan::call('key:generate', ['--force' => true], $this->output);
        }

        return $ok;
    }

    private function createAdminAccount(): void
    {
        $name = $this->components->ask('Admin name', 'Admin');
        $email = $this->components->ask('Admin email', 'admin@agentfleet.local');
        $password = $this->secret('Admin password');

        if (! $password) {
            $password = 'password';
            $this->components->warn('No password entered. Using default: "password"');
        }

        DB::transaction(function () use ($name, $email, $password) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]);

            $team = Team::firstOrCreate(
                ['slug' => 'default'],
                [
                    'name' => config('app.name', 'Agent Fleet'),
                    'owner_id' => $user->id,
                    'plan' => 'community',
                ]
            );

            // If team already existed, update owner
            if ($team->wasRecentlyCreated === false) {
                $team->update(['owner_id' => $user->id]);
            }

            $team->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

            $user->update(['current_team_id' => $team->id]);
        });

        $this->components->info("Admin account created: {$email}");
    }

    private function configureLlmProvider(): void
    {
        $providers = [
            'anthropic' => ['name' => 'Anthropic (Claude)', 'env' => 'ANTHROPIC_API_KEY'],
            'openai' => ['name' => 'OpenAI (GPT-4o)', 'env' => 'OPENAI_API_KEY'],
            'google' => ['name' => 'Google (Gemini)', 'env' => 'GOOGLE_AI_API_KEY'],
            'skip' => ['name' => 'Skip — configure later in Settings', 'env' => null],
        ];

        $choice = $this->components->choice(
            'Select LLM provider',
            array_map(fn ($p) => $p['name'], $providers),
            3
        );

        $selected = null;
        foreach ($providers as $key => $provider) {
            if ($provider['name'] === $choice) {
                $selected = $key;
                break;
            }
        }

        if ($selected === 'skip' || $selected === null) {
            $this->components->info('You can add LLM API keys later in Settings or .env');
            return;
        }

        $apiKey = $this->secret('API Key');

        if (! $apiKey) {
            $this->components->warn('No key entered. You can configure it later in Settings.');
            return;
        }

        $envVar = $providers[$selected]['env'];
        $this->setEnvValue($envVar, $apiKey);
        $this->components->info("Saved {$envVar} to .env");
    }

    private function detectLocalAgents(): void
    {
        $this->components->info('Scanning for local AI agents...');
        $this->newLine();

        $discovery = app(LocalAgentDiscovery::class);
        $detected = $discovery->detect();

        if (empty($detected)) {
            if ($discovery->isBridgeMode()) {
                $this->components->warn('Running in Docker — bridge mode active but no agents found on host.');
                $this->components->info('Ensure the host bridge is running:');
                $this->components->bulletList([
                    'LOCAL_AGENT_BRIDGE_SECRET=your-secret php -S 0.0.0.0:8065 docker/host-bridge.php',
                ]);
            } else {
                $inDocker = env('RUNNING_IN_DOCKER') || file_exists('/.dockerenv');
                if ($inDocker) {
                    $this->components->warn('Running in Docker — local agents require the host bridge.');
                    $this->components->info('On the host machine, run:');
                    $this->components->bulletList([
                        'LOCAL_AGENT_BRIDGE_SECRET=your-secret php -S 0.0.0.0:8065 docker/host-bridge.php',
                        'Then set LOCAL_AGENT_BRIDGE_SECRET in .env',
                    ]);
                } else {
                    $this->components->info('No local agents detected. You can install them later:');
                    $this->components->bulletList([
                        'Codex: npm install -g @openai/codex',
                        'Claude Code: see https://docs.anthropic.com/en/docs/claude-code',
                    ]);
                }
            }
            return;
        }

        foreach ($detected as $key => $agent) {
            $this->components->twoColumnDetail(
                "{$agent['name']} v{$agent['version']}",
                "<fg=green>Available at {$agent['path']}</>"
            );
        }

        $this->newLine();
        $count = count($detected);
        $this->components->info("Detected {$count} local agent(s). These can be assigned to skills and agents for local code generation, file editing, and multi-step task execution.");

        if ($this->option('force')) {
            $enable = true;
        } else {
            $enable = $this->components->confirm('Enable local agents?', true);
        }

        if ($enable) {
            $this->setEnvValue('LOCAL_AGENTS_ENABLED', 'true');
            $this->components->info('Local agents enabled.');
        } else {
            $this->setEnvValue('LOCAL_AGENTS_ENABLED', 'false');
            $this->components->info('Local agents disabled. Enable later in Settings or .env.');
        }
    }

    private function setEnvValue(string $key, string $value): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            return;
        }

        $content = file_get_contents($envPath);

        // Replace existing key or append
        if (preg_match("/^{$key}=.*/m", $content)) {
            $content = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $content);
        } else {
            $content .= "\n{$key}={$value}\n";
        }

        file_put_contents($envPath, $content);
    }
}
