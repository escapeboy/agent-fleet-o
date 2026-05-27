<?php

namespace App\Http\Controllers;

use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Skill\Enums\SkillLiftStatus;
use App\Domain\Skill\Models\SkillLiftEvaluation;
use Illuminate\Contracts\View\View;

/**
 * Public, unauthenticated skill-quality leaderboard. Ranks published skill listings
 * by their community quality score, enriched with the ZooEval-style blind A/B lift
 * (with-skill vs without-skill) when an evaluation has been run. Mirrors the public
 * integrations directory pattern (Alpine client-side filter, no auth chrome).
 */
class SkillQualityLeaderboardController extends Controller
{
    public function __invoke(): View
    {
        $listings = MarketplaceListing::query()
            ->where('status', MarketplaceStatus::Published)
            ->where('visibility', ListingVisibility::Public)
            ->where('type', 'skill')
            ->orderByRaw('community_quality_score IS NULL ASC')
            ->orderByDesc('community_quality_score')
            ->limit(100)
            ->get();

        $skillIds = $listings->pluck('listable_id')->filter()->all();

        $lifts = SkillLiftEvaluation::withoutGlobalScopes()
            ->whereIn('skill_id', $skillIds)
            ->where('status', SkillLiftStatus::Completed)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('skill_id')
            ->map(fn ($group) => $group->first());

        return view('skills.quality-leaderboard', [
            'listings' => $listings,
            'lifts' => $lifts,
        ]);
    }
}
