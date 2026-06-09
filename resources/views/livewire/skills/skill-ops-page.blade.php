<div class="space-y-6" wire:poll.10s>
    @if (session('message'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('message') }}
        </div>
    @endif
    @if (session('benchmark_error'))
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ session('benchmark_error') }}
        </div>
    @endif

    {{-- Tabs --}}
    <div class="border-b border-gray-200">
        <nav class="-mb-px flex gap-6">
            @php($tabs = ['loop' => 'Improvement Loop', 'benchmarks' => 'Benchmarks', 'annotations' => 'Annotations'])
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')"
                    class="border-b-2 px-1 py-3 text-sm font-medium {{ $tab === $key ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- ============ IMPROVEMENT LOOP ============ --}}
    @if ($tab === 'loop')
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-white p-5 lg:col-span-1">
                <h3 class="mb-1 text-sm font-semibold text-gray-900">Start metric-gated loop</h3>
                <p class="mb-4 text-xs text-gray-500">
                    Generates candidate skill versions and keeps only those that improve the metric.
                    Runs in the background; refresh the Benchmarks tab to track progress.
                </p>
                <div class="space-y-3">
                    <x-form-select wire:model="skillId" label="Skill">
                        @foreach ($skills as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </x-form-select>
                    <x-form-input wire:model="loopMetric" label="Metric" />
                    <x-form-input wire:model="loopMaxIterations" type="number" label="Max iterations" />
                    <button type="button" wire:click="startLoop"
                        class="w-full rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                        Start improvement loop
                    </button>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-5 lg:col-span-2">
                <h3 class="mb-3 text-sm font-semibold text-gray-900">Running loops &amp; benchmarks</h3>
                @if ($runningBenchmarks->isEmpty())
                    <p class="text-sm text-gray-500">No loops currently running.</p>
                @else
                    <div class="space-y-3">
                        @foreach ($runningBenchmarks as $b)
                            <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">{{ $b->skill?->name ?? '—' }}</p>
                                        <p class="text-xs text-gray-500">
                                            {{ $b->metric_name }} · iteration {{ $b->iteration_count }}/{{ $b->max_iterations }}
                                            · best {{ $b->best_value !== null ? number_format($b->best_value, 4) : '—' }}
                                            ({{ $b->improvementPercent() }}%)
                                        </p>
                                    </div>
                                    <button type="button" wire:click="cancelBenchmark('{{ $b->id }}')"
                                        class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ============ BENCHMARKS ============ --}}
    @if ($tab === 'benchmarks')
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-white p-5 lg:col-span-1">
                <h3 class="mb-4 text-sm font-semibold text-gray-900">Start benchmark</h3>
                <div class="space-y-3">
                    <x-form-select wire:model="benchSkillId" label="Skill">
                        @foreach ($skills as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </x-form-select>
                    <x-form-input wire:model="benchMetricName" label="Metric name" />
                    <x-form-select wire:model="benchMetricDirection" label="Direction">
                        <option value="maximize">Maximize</option>
                        <option value="minimize">Minimize</option>
                    </x-form-select>
                    <x-form-textarea wire:model="benchTestInputs" label="Test inputs (JSON array)" mono rows="4" />
                    <div class="grid grid-cols-2 gap-3">
                        <x-form-input wire:model="benchMaxIterations" type="number" label="Max iterations" />
                        <x-form-input wire:model="benchTimeBudget" type="number" label="Time budget (s)" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-form-input wire:model="benchComplexityPenalty" type="number" step="0.01" label="Complexity penalty" />
                        <x-form-input wire:model="benchImprovementThreshold" type="number" step="0.01" label="Improvement threshold" />
                    </div>
                    <button type="button" wire:click="startBenchmark"
                        class="w-full rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                        Start benchmark
                    </button>
                </div>
            </div>

            <div class="lg:col-span-2">
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                            <tr>
                                <th class="px-4 py-3">Skill</th>
                                <th class="px-4 py-3">Metric</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Iters</th>
                                <th class="px-4 py-3">Best</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($benchmarks as $b)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $b->skill?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ $b->metric_name }} ({{ $b->metric_direction }})</td>
                                    <td class="px-4 py-3">
                                        @php($colors = ['running' => 'bg-blue-100 text-blue-700', 'completed' => 'bg-green-100 text-green-700', 'cancelled' => 'bg-gray-100 text-gray-600', 'failed' => 'bg-red-100 text-red-700', 'pending' => 'bg-amber-100 text-amber-700'])
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $colors[$b->status->value] ?? 'bg-gray-100 text-gray-600' }}">
                                            {{ $b->status->label() }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600">{{ $b->iteration_count }}/{{ $b->max_iterations }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ $b->best_value !== null ? number_format($b->best_value, 4) : '—' }}</td>
                                    <td class="px-4 py-3 text-right">
                                        @if ($b->status === \App\Domain\Skill\Enums\BenchmarkStatus::Running)
                                            <button type="button" wire:click="cancelBenchmark('{{ $b->id }}')"
                                                class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50">
                                                Cancel
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No benchmarks yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- ============ ANNOTATIONS ============ --}}
    @if ($tab === 'annotations')
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-white p-5 lg:col-span-1">
                <h3 class="mb-1 text-sm font-semibold text-gray-900">Annotate a response</h3>
                <p class="mb-4 text-xs text-gray-500">
                    Rate a skill version's output. Annotations feed
                    GenerateImprovedSkillVersionAction's few-shot meta-prompting.
                </p>
                <div class="space-y-3">
                    <x-form-select wire:model="annotateVersionId" label="Skill version">
                        <option value="">Select a version…</option>
                        @foreach ($versions as $v)
                            <option value="{{ $v->id }}">{{ $v->skill?->name ?? '—' }} · v{{ $v->version }}</option>
                        @endforeach
                    </x-form-select>
                    <x-form-input wire:model="annotateModelId" label="Model" placeholder="anthropic/claude-sonnet-4-5" />
                    <x-form-textarea wire:model="annotateInput" label="Input" mono rows="3" />
                    <x-form-textarea wire:model="annotateOutput" label="Output" mono rows="4" />
                    <x-form-select wire:model="annotateRating" label="Rating">
                        <option value="good">Good</option>
                        <option value="bad">Bad</option>
                    </x-form-select>
                    <x-form-input wire:model="annotateNote" label="Note (optional)" />
                    <button type="button" wire:click="submitAnnotation"
                        class="w-full rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                        Submit annotation
                    </button>
                </div>
            </div>

            <div class="lg:col-span-2">
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                            <tr>
                                <th class="px-4 py-3">Skill / Version</th>
                                <th class="px-4 py-3">Model</th>
                                <th class="px-4 py-3">Rating</th>
                                <th class="px-4 py-3">By</th>
                                <th class="px-4 py-3">When</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($annotations as $a)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-900">
                                        {{ $a->skillVersion?->skill?->name ?? '—' }}
                                        <span class="text-gray-400">· v{{ $a->skillVersion?->version ?? '?' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600">{{ $a->model_id }}</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $a->rating === \App\Domain\Skill\Enums\AnnotationRating::Good ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                            {{ ucfirst($a->rating->value) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600">{{ $a->user?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $a->created_at?->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No annotations yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
