<?php

namespace App\Livewire\Setup;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Notifications\WelcomeNotification;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.auth', ['title' => 'Setup — FleetQ'])]
class SetupPage extends Component
{
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    /** @var array<string, array{status: string, detail: string, hint?: string}> */
    public array $checks = [];

    public bool $blockerPresent = true;

    public function mount(): void
    {
        // Guard: if already installed, send to login
        try {
            if (User::exists()) {
                $this->redirect(route('login'), navigate: true);

                return;
            }
        } catch (\Throwable) {
            // DB down — fall through to show error checks
        }

        $this->runChecks();
    }

    public function recheck(): void
    {
        $this->runChecks();
    }

    public function createAccount(): void
    {
        Validator::make([
            'name'                  => $this->name,
            'email'                 => $this->email,
            'password'              => $this->password,
            'password_confirmation' => $this->password_confirmation,
        ], [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ])->validate();

        DB::transaction(function () {
            // Create admin user
            $user = User::create([
                'name'              => $this->name,
                'email'             => strtolower($this->email),
                'password'          => Hash::make($this->password),
                'email_verified_at' => now(), // no email verification in self-hosted
            ]);

            // Create or find the default team
            $team = Team::firstOrCreate(
                ['slug' => 'default'],
                [
                    'name'     => config('app.name', 'FleetQ'),
                    'owner_id' => $user->id,
                    'plan'     => 'community',
                ],
            );

            // Ensure owner_id is set (team may have existed without an owner)
            if (! $team->wasRecentlyCreated) {
                $team->update(['owner_id' => $user->id]);
            }

            // Attach as owner
            $team->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

            $user->update(['current_team_id' => $team->id]);

            try {
                $user->notify(new WelcomeNotification($team));
            } catch (\Throwable) {
                // Mail may not be configured yet — do not fail setup
            }

            Auth::login($user);
        });

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.setup.setup-page');
    }

    private function runChecks(): void
    {
        $this->checks = [];
        $blocking = false;

        // 1. Database connection (CRITICAL — blocks form)
        try {
            DB::connection()->getPdo();
            $this->checks['database'] = ['status' => 'ok', 'detail' => 'Database connected'];
        } catch (\Throwable $e) {
            $this->checks['database'] = [
                'status' => 'fail',
                'detail' => 'Cannot connect to database',
                'hint'   => 'Check DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, and DB_PASSWORD in your .env file. Error: ' . $e->getMessage(),
            ];
            $blocking = true;
        }

        // 2. Migrations run — users table must exist (CRITICAL — blocks form)
        if (! $blocking) {
            try {
                $hasMigrations = DB::connection()->getSchemaBuilder()->hasTable('users');

                if ($hasMigrations) {
                    $this->checks['migrations'] = ['status' => 'ok', 'detail' => 'Database schema ready'];
                } else {
                    $this->checks['migrations'] = [
                        'status' => 'fail',
                        'detail' => 'Database tables not found',
                        'hint'   => 'Run migrations: php artisan migrate',
                    ];
                    $blocking = true;
                }
            } catch (\Throwable) {
                $this->checks['migrations'] = [
                    'status' => 'fail',
                    'detail' => 'Cannot verify database schema',
                    'hint'   => 'Run migrations: php artisan migrate',
                ];
                $blocking = true;
            }
        }

        // 3. APP_KEY set (CRITICAL — blocks form)
        if (empty(config('app.key'))) {
            $this->checks['app_key'] = [
                'status' => 'fail',
                'detail' => 'Application key is not set',
                'hint'   => 'Generate one: php artisan key:generate',
            ];
            $blocking = true;
        } else {
            $this->checks['app_key'] = ['status' => 'ok', 'detail' => 'Application key configured'];
        }

        // 4. Redis connection (WARNING — form still shown)
        try {
            Redis::connection()->ping();
            $this->checks['redis'] = ['status' => 'ok', 'detail' => 'Redis connected'];
        } catch (\Throwable $e) {
            $this->checks['redis'] = [
                'status' => 'warn',
                'detail' => 'Cannot connect to Redis',
                'hint'   => 'Queue workers and sessions require Redis. Check REDIS_HOST and REDIS_PORT in .env.',
            ];
        }

        // 5. LLM provider (WARNING — form still shown, can configure later in Settings)
        $hasLlm = ! empty(config('services.anthropic.api_key'))
            || ! empty(config('services.openai.api_key'))
            || ! empty(config('services.google.api_key'));

        $this->checks['llm'] = $hasLlm
            ? ['status' => 'ok', 'detail' => 'LLM provider configured']
            : [
                'status' => 'warn',
                'detail' => 'No LLM API key found',
                'hint'   => 'Set ANTHROPIC_API_KEY, OPENAI_API_KEY, or GOOGLE_AI_API_KEY in .env. You can also configure this later in Settings.',
            ];

        $this->blockerPresent = $blocking;
    }
}
