<?php

namespace Database\Factories\Domain\Audience;

use App\Domain\Audience\Enums\AudienceMemberStatus;
use App\Domain\Audience\Models\Audience;
use App\Domain\Audience\Models\AudienceMember;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class AudienceMemberFactory extends Factory
{
    protected $model = AudienceMember::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'audience_id' => Audience::factory(),
            'contact_identity_id' => ContactIdentity::factory(),
            'status' => AudienceMemberStatus::Subscribed,
            'subscribed_at' => now(),
            'unsubscribed_at' => null,
            'unsubscribe_reason' => null,
        ];
    }
}
