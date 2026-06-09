<div class="mx-auto max-w-4xl px-4 py-6">
    @php($execution = $this->execution)

    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold text-gray-800">Crew Chat Room</h1>
            <p class="mt-0.5 text-sm text-gray-500">
                Inter-agent messaging &amp; blackboard for crew execution
                <span class="font-mono text-xs text-gray-400">{{ $execution->id }}</span>
            </p>
        </div>
        <a href="{{ route('crews.show', $execution->crew_id) }}"
            class="rounded bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-200">
            <i class="fa-solid fa-arrow-left mr-1"></i> Back to crew
        </a>
    </div>

    {{-- Goal --}}
    @if($execution->goal)
        <div class="mb-6 rounded-lg border border-gray-100 bg-gray-50 p-3 text-sm text-gray-600">
            <span class="font-medium text-gray-700">Goal:</span> {{ $execution->goal }}
        </div>
    @endif

    {{-- Blackboard --}}
    <div class="mb-8">
        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">
            Blackboard
            <span class="ml-1 font-normal normal-case text-gray-400">(shared board · ephemeral, 24h)</span>
        </h2>
        @php($posts = $this->blackboardPosts)
        @if(empty($posts))
            <p class="rounded-lg border border-dashed border-gray-200 px-3 py-4 text-center text-sm text-gray-400">
                No blackboard posts. Posts are stored in Redis with a 24-hour TTL and disappear after that window.
            </p>
        @else
            <div class="space-y-2">
                @foreach($posts as $post)
                    <div class="flex items-start gap-2 rounded-lg border border-gray-100 bg-white p-2.5">
                        <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold
                            {{ match($post['type'] ?? '') {
                                'STATUS' => 'bg-blue-50 text-blue-700',
                                'QUESTION' => 'bg-amber-50 text-amber-700',
                                'FINDING' => 'bg-green-50 text-green-700',
                                default => 'bg-gray-100 text-gray-600',
                            } }}">
                            {{ $post['type'] ?? '—' }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="mb-0.5 flex items-center gap-1.5">
                                <span class="text-[10px] font-medium text-gray-600">{{ $post['agent_name'] ?? 'unknown' }}</span>
                                @if(!empty($post['ts']))
                                    <span class="text-[10px] text-gray-400">{{ \Illuminate\Support\Carbon::parse($post['ts'])->diffForHumans() }}</span>
                                @endif
                            </div>
                            <div class="whitespace-pre-wrap text-sm text-gray-700">{{ $post['message'] ?? '' }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Chat-room transcript --}}
    @php($chatMessages = $this->chatMessages)
    @if($chatMessages->isNotEmpty())
        <div class="mb-8">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Chat Room Discussion</h2>
            <div class="space-y-2">
                @php($currentRound = 0)
                @foreach($chatMessages as $msg)
                    @if($msg->round !== $currentRound)
                        @php($currentRound = $msg->round)
                        @if($currentRound > 0)
                            <div class="my-2 flex items-center gap-2">
                                <div class="h-px flex-1 bg-gray-200"></div>
                                <span class="text-[10px] font-medium text-gray-400">Round {{ $currentRound }}</span>
                                <div class="h-px flex-1 bg-gray-200"></div>
                            </div>
                        @endif
                    @endif
                    <div class="flex gap-2 {{ $msg->role === 'system' ? 'justify-center' : '' }}">
                        @if($msg->role === 'system')
                            <div class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs italic text-gray-500">{{ $msg->content }}</div>
                        @else
                            <div class="flex-1 rounded-lg border border-gray-100 bg-white p-2.5">
                                <div class="mb-1 flex items-center gap-1.5">
                                    <span class="inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">{{ $msg->agent_name ?? $msg->agent?->name ?? 'agent' }}</span>
                                    <span class="text-[10px] text-gray-400">{{ $msg->created_at?->diffForHumans() }}</span>
                                </div>
                                <div class="whitespace-pre-wrap text-sm text-gray-700">{{ \Illuminate\Support\Str::limit($msg->content, 4000) }}</div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Inter-agent messages --}}
    <div>
        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Inter-Agent Messages</h2>
        @php($agentMessages = $this->agentMessages)
        @if($agentMessages->isEmpty())
            <p class="rounded-lg border border-dashed border-gray-200 px-3 py-4 text-center text-sm text-gray-400">
                No inter-agent messages for this execution.
            </p>
        @else
            <div class="space-y-2">
                @php($currentRound = -1)
                @foreach($agentMessages as $msg)
                    @if($msg->round !== $currentRound)
                        @php($currentRound = $msg->round)
                        <div class="my-2 flex items-center gap-2">
                            <div class="h-px flex-1 bg-gray-200"></div>
                            <span class="text-[10px] font-medium text-gray-400">Round {{ $currentRound }}</span>
                            <div class="h-px flex-1 bg-gray-200"></div>
                        </div>
                    @endif
                    <div class="rounded-lg border border-gray-100 bg-white p-2.5">
                        <div class="mb-1 flex flex-wrap items-center gap-1.5">
                            <span class="inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">
                                {{ $msg->sender?->name ?? 'system' }}
                            </span>
                            <i class="fa-solid fa-arrow-right text-[9px] text-gray-300"></i>
                            <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold
                                {{ $msg->recipient_agent_id ? 'bg-gray-100 text-gray-600' : 'bg-purple-50 text-purple-700' }}">
                                {{ $msg->recipient?->name ?? 'broadcast' }}
                            </span>
                            <span class="inline-flex rounded-full bg-gray-50 px-2 py-0.5 text-[10px] font-medium text-gray-500">{{ $msg->message_type }}</span>
                            <span class="text-[10px] text-gray-400">{{ $msg->created_at?->diffForHumans() }}</span>
                        </div>
                        <div class="whitespace-pre-wrap text-sm text-gray-700">{{ \Illuminate\Support\Str::limit($msg->content, 4000) }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
