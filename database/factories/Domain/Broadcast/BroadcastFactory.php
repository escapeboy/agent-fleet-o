<?php

namespace Database\Factories\Domain\Broadcast;

use App\Domain\Audience\Models\Audience;
use App\Domain\Broadcast\Enums\BroadcastStatus;
use App\Domain\Broadcast\Models\Broadcast;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class BroadcastFactory extends Factory
{
    protected $model = Broadcast::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'audience_id' => Audience::factory(),
            'name' => fake()->sentence(3),
            'subject' => fake()->sentence(5),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'status' => BroadcastStatus::Draft,
            'recipient_count' => 0,
        ];
    }
}
