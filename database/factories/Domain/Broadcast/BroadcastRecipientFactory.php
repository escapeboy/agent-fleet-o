<?php

namespace Database\Factories\Domain\Broadcast;

use App\Domain\Broadcast\Enums\BroadcastRecipientStatus;
use App\Domain\Broadcast\Models\Broadcast;
use App\Domain\Broadcast\Models\BroadcastRecipient;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class BroadcastRecipientFactory extends Factory
{
    protected $model = BroadcastRecipient::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'broadcast_id' => Broadcast::factory(),
            'contact_identity_id' => ContactIdentity::factory(),
            'email' => fake()->unique()->safeEmail(),
            'status' => BroadcastRecipientStatus::Pending,
        ];
    }
}
