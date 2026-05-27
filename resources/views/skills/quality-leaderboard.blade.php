<x-layouts.public
    title="Skill Quality Leaderboard — FleetQ"
    description="Published FleetQ skills ranked by community quality and blind A/B lift (with-skill vs without-skill)."
    keywords="AI skills, skill quality, agent skills, ZooEval, leaderboard"
>
    <div class="mx-auto max-w-5xl px-4 py-12 sm:px-6 lg:px-8"
         x-data="{ q: '', rec: '' }">
        <header class="mb-8">
            <h1 class="text-3xl font-bold tracking-tight text-gray-900">Skill Quality Leaderboard</h1>
            <p class="mt-2 max-w-2xl text-gray-600">
                Published skills ranked by community quality score, enriched with a blind A/B
                <strong>lift</strong> — the judged difference between running the skill and not running it.
            </p>
        </header>

        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <input type="search" x-model="q" placeholder="Search skills…"
                       class="w-full rounded-lg border border-gray-300 py-2.5 pl-10 pr-3 focus:border-primary-500 focus:ring-primary-500">
            </div>
            <select x-model="rec"
                    class="rounded-lg border border-gray-300 py-2.5 focus:border-primary-500 focus:ring-primary-500">
                <option value="">All recommendations</option>
                <option value="highly_recommended">Highly Recommended</option>
                <option value="recommended">Recommended</option>
                <option value="conditional">Conditional</option>
                <option value="marginal">Marginal</option>
                <option value="harmful">Harmful</option>
            </select>
        </div>

        @if($listings->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-300 p-12 text-center text-gray-500">
                No published skills yet.
            </div>
        @else
            <ol class="space-y-3">
                @foreach($listings as $i => $listing)
                    @php
                        $lift = $lifts[$listing->listable_id] ?? null;
                        $rec = $lift?->recommendation;
                        $score = $listing->community_quality_score !== null
                            ? round($listing->community_quality_score * 100)
                            : null;
                    @endphp
                    <li class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:shadow-md"
                        x-show="(q === '' || @js(mb_strtolower($listing->name)).includes(q.toLowerCase()))
                                && (rec === '' || rec === @js($rec?->value ?? ''))"
                        x-transition.opacity>
                        <div class="flex items-start gap-4">
                            <div class="mt-1 w-8 shrink-0 text-center text-lg font-bold text-gray-400">
                                {{ $i + 1 }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="truncate font-semibold text-gray-900">{{ $listing->name }}</h2>
                                    @if($listing->category)
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $listing->category }}</span>
                                    @endif
                                    @if($rec)
                                        <span @class([
                                            'rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-green-100 text-green-800' => $rec->badgeColor() === 'green',
                                            'bg-emerald-100 text-emerald-800' => $rec->badgeColor() === 'emerald',
                                            'bg-amber-100 text-amber-800' => $rec->badgeColor() === 'amber',
                                            'bg-gray-100 text-gray-700' => $rec->badgeColor() === 'gray',
                                            'bg-red-100 text-red-800' => $rec->badgeColor() === 'red',
                                        ])>{{ $rec->label() }}</span>
                                    @endif
                                </div>
                                @if($listing->description)
                                    <p class="mt-1 line-clamp-2 text-sm text-gray-600">{{ $listing->description }}</p>
                                @endif
                                <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
                                    <span class="text-gray-500">
                                        <i class="fa-solid fa-download mr-1 text-gray-400"></i>{{ $listing->install_count }}
                                    </span>
                                    @if($score !== null)
                                        <span class="text-gray-700">
                                            Quality <strong>{{ $score }}%</strong>
                                        </span>
                                    @endif
                                    @if($lift && $lift->delta !== null)
                                        <span @class([
                                            'font-medium',
                                            'text-green-700' => (float) $lift->delta > 0,
                                            'text-red-700' => (float) $lift->delta < 0,
                                            'text-gray-500' => (float) $lift->delta == 0,
                                        ])>
                                            Lift {{ (float) $lift->delta > 0 ? '+' : '' }}{{ $lift->delta }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</x-layouts.public>
