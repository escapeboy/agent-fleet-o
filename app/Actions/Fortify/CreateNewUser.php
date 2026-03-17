<?php

namespace App\Actions\Fortify;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Notifications\WelcomeNotification;
use App\Domain\Shared\Services\TermsAcceptanceService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function __construct(
        private readonly TermsAcceptanceService $terms,
        private readonly Request $request,
    ) {}

    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => 'You must agree to the Terms of Service and Privacy Policy.',
        ])->validate();

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
                'email_verified_at' => now(),
            ]);

            // Attach to the default team (created during app:install)
            $team = Team::where('slug', 'default')->first();

            if (! $team) {
                throw new \RuntimeException(
                    'Default team not found. Run `php artisan app:install` before registering users.',
                );
            }

            $team->users()->attach($user->id, ['role' => 'member']);

            $user->update(['current_team_id' => $team->id]);

            $user->notify(new WelcomeNotification($team));

            $this->terms->record($user, $this->request, 'registration_form');

            return $user;
        });
    }
}
