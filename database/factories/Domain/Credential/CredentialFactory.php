<?php

namespace Database\Factories\Domain\Credential;

use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CredentialFactory extends Factory
{
    protected $model = Credential::class;

    public function definition(): array
    {
        $name = fake()->words(2, true).' Credential';

        return [
            'team_id' => Team::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Active,
            'secret_data' => ['token' => fake()->sha256()],
            'metadata' => [],
        ];
    }

    public function expired(): static
    {
        return $this->state([
            'expires_at' => now()->subDay(),
        ]);
    }
}
