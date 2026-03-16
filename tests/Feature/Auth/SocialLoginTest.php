<?php

namespace Tests\Feature\Auth;

use App\Domain\Shared\Models\UserSocialAccount;
use App\Models\User;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Disable rate limiting and CSRF — both interfere with in-process test requests
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class, VerifyCsrfToken::class, ValidateCsrfToken::class]);
    }

    /** Create a user with a team (required because cloud EnsureTeamExists middleware redirects teamless users to /onboarding) */
    protected function userWithTeam(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $team = Team::create([
            'name'     => 'Test Team',
            'slug'     => 'test-team-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'owner']);

        return $user;
    }

    protected function mockSocialiteUser(
        string $id = '12345',
        string $email = 'social@example.com',
        string $name = 'Social User',
        ?string $token = 'access-token',
        ?string $refreshToken = null,
        ?int $expiresIn = 3600,
    ): SocialiteUser {
        $mock = Mockery::mock(SocialiteUser::class);
        $mock->shouldReceive('getId')->andReturn($id);
        $mock->shouldReceive('getEmail')->andReturn($email);
        $mock->shouldReceive('getName')->andReturn($name);
        $mock->shouldReceive('getAvatar')->andReturn(null);
        $mock->token = $token;
        $mock->refreshToken = $refreshToken;
        $mock->expiresIn = $expiresIn;
        $mock->user = [];

        return $mock;
    }

    /** Create a Socialite driver mock that accepts enablePKCE() then returns the $socialUser on ->user() */
    protected function mockDriver(SocialiteUser $socialUser): SocialiteProvider
    {
        $driver = Mockery::mock(SocialiteProvider::class);
        $driver->shouldReceive('enablePKCE')->andReturnSelf()->byDefault();
        $driver->shouldReceive('user')->andReturn($socialUser);
        $driver->shouldReceive('redirect')->andReturn(redirect('https://provider.example.com/oauth'));

        return $driver;
    }

    // ── redirect ─────────────────────────────────────────────────────────────

    public function test_redirect_returns_redirect_for_supported_provider(): void
    {
        $driver = $this->mockDriver($this->mockSocialiteUser());
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $response = $this->get(route('auth.social.redirect', 'google'));

        $response->assertRedirect();
    }

    public function test_redirect_returns_404_for_unsupported_provider(): void
    {
        $response = $this->get(route('auth.social.redirect', 'unsupported'));

        $response->assertNotFound();
    }

    // ── callback: new user ────────────────────────────────────────────────────

    public function test_callback_creates_new_user_for_google(): void
    {
        $socialUser = $this->mockSocialiteUser(email: 'new@example.com');
        $driver = $this->mockDriver($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $response = $this->get(route('auth.social.callback', 'google'));

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'password' => null]);
        $this->assertDatabaseHas('user_social_accounts', ['provider' => 'google', 'provider_user_id' => '12345']);

        // Google is a verified provider — email_verified_at should be set
        $user = User::where('email', 'new@example.com')->first();
        $this->assertNotNull($user->email_verified_at);
    }

    // ── callback: existing social account (returning user) ───────────────────

    public function test_callback_logs_in_existing_social_account_user(): void
    {
        $user = User::factory()->create(['email' => 'returning@example.com']);
        UserSocialAccount::create([
            'user_id'          => $user->id,
            'provider'         => 'google',
            'provider_user_id' => '99999',
            'email'            => 'returning@example.com',
        ]);

        $socialUser = $this->mockSocialiteUser(id: '99999', email: 'returning@example.com');
        $driver = $this->mockDriver($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $response = $this->get(route('auth.social.callback', 'google'));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    // ── callback: email match auto-link for trusted providers ────────────────

    public function test_callback_auto_links_verified_provider_to_existing_email(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.com', 'password' => bcrypt('secret')]);

        $socialUser = $this->mockSocialiteUser(email: 'existing@example.com');
        $driver = $this->mockDriver($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $response = $this->get(route('auth.social.callback', 'google'));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'google']);
    }

    // ── callback: email collect for providers that may omit it ───────────────

    public function test_callback_redirects_to_collect_email_when_email_missing(): void
    {
        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn('77777');
        $socialUser->shouldReceive('getEmail')->andReturn(null);
        $socialUser->shouldReceive('getName')->andReturn('No Email User');
        $socialUser->shouldReceive('getAvatar')->andReturn(null);
        $socialUser->token = 'tok';
        $socialUser->refreshToken = null;
        $socialUser->expiresIn = null;
        $socialUser->user = [];

        $driver = $this->mockDriver($socialUser);
        Socialite::shouldReceive('driver')->with('x')->andReturn($driver);

        $response = $this->get(route('auth.social.callback', 'x'));

        $response->assertRedirect(route('auth.social.collect-email'));
        $this->assertNotNull(session('pending_social_auth'));
    }

    public function test_store_email_rejects_existing_email_to_prevent_account_takeover(): void
    {
        // Simulate a pending social auth session (provider gave no email)
        session(['pending_social_auth' => [
            'provider'    => 'x',
            'provider_id' => '77777',
            'name'        => 'Attacker',
            'avatar'      => null,
        ]]);

        $victim = User::factory()->create(['email' => 'victim@example.com']);

        $response = $this->post(route('auth.social.store-email'), ['email' => 'victim@example.com']);

        // Must NOT log in as the victim
        $response->assertSessionHasErrors(['email']);
        $this->assertGuest();

        // Victim's account must remain untouched (no social account added)
        $this->assertDatabaseMissing('user_social_accounts', ['user_id' => $victim->id]);
    }

    public function test_store_email_creates_new_account_for_fresh_email(): void
    {
        session(['pending_social_auth' => [
            'provider'    => 'x',
            'provider_id' => '88888',
            'name'        => 'New User',
            'avatar'      => null,
        ]]);

        $response = $this->post(route('auth.social.store-email'), ['email' => 'newuser@example.com']);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', ['email' => 'newuser@example.com']);
        $this->assertDatabaseHas('user_social_accounts', ['provider' => 'x', 'provider_user_id' => '88888']);
    }

    // ── callback: X/Apple auto-link (all providers are verified) ────────────

    public function test_callback_x_auto_links_to_existing_account_with_matching_email(): void
    {
        $user = User::factory()->create(['email' => 'x-user@example.com']);

        $socialUser = $this->mockSocialiteUser(id: '55555', email: 'x-user@example.com');
        $driver     = $this->mockDriver($socialUser);
        Socialite::shouldReceive('driver')->with('x')->andReturn($driver);

        $response = $this->get(route('auth.social.callback', 'x'));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'x']);
    }

    // ── unlink: safety guard ─────────────────────────────────────────────────

    public function test_unlink_prevents_removing_last_auth_method(): void
    {
        $user = $this->userWithTeam(['password' => null]);
        UserSocialAccount::create([
            'user_id'          => $user->id,
            'provider'         => 'google',
            'provider_user_id' => '111',
            'email'            => $user->email,
        ]);

        $response = $this->actingAs($user)->delete(route('auth.social.unlink', 'google'));

        $response->assertSessionHasErrors(['social']);
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'google']);
    }

    public function test_unlink_succeeds_when_user_has_password(): void
    {
        $user = $this->userWithTeam(['password' => bcrypt('secret')]);
        UserSocialAccount::create([
            'user_id'          => $user->id,
            'provider'         => 'google',
            'provider_user_id' => '222',
            'email'            => $user->email,
        ]);

        $response = $this->actingAs($user)->delete(route('auth.social.unlink', 'google'));

        $response->assertRedirect();
        $this->assertDatabaseMissing('user_social_accounts', ['user_id' => $user->id, 'provider' => 'google']);
    }

    public function test_unlink_succeeds_when_multiple_social_accounts_exist(): void
    {
        $user = $this->userWithTeam(['password' => null]);
        UserSocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => '333', 'email' => $user->email]);
        UserSocialAccount::create(['user_id' => $user->id, 'provider' => 'github', 'provider_user_id' => '444', 'email' => $user->email]);

        $response = $this->actingAs($user)->delete(route('auth.social.unlink', 'github'));

        $response->assertRedirect();
        $this->assertDatabaseMissing('user_social_accounts', ['user_id' => $user->id, 'provider' => 'github']);
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'google']);
    }

    // ── Apple POST callback ───────────────────────────────────────────────────

    public function test_apple_callback_accepts_post_without_csrf(): void
    {
        $socialUser = $this->mockSocialiteUser(id: 'apple.uid.abc', email: 'apple@example.com');
        $driver = $this->mockDriver($socialUser);
        Socialite::shouldReceive('driver')->with('apple')->andReturn($driver);

        $response = $this->post(route('auth.apple.callback'));

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('user_social_accounts', ['provider' => 'apple', 'provider_user_id' => 'apple.uid.abc']);
    }
}
