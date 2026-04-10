<x-layouts.docs
    title="Compute & GPU Resources"
    description="FleetQ compute management — GPU providers, RunPod integration, Bridge local hardware, and cost tracking for AI workloads."
    page="compute"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Compute &amp; GPU Resources</h1>
    <p class="mt-4 text-gray-600">
        FleetQ can manage compute resources for AI workloads. Connect GPU providers like RunPod for tasks
        requiring dedicated compute — fine-tuning, large model inference, or batch processing.
    </p>

    {{-- Compute providers --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Compute providers</h2>
    <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-4 text-left font-semibold text-gray-700">Provider</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-3 pl-4 pr-4 font-medium text-gray-900">RunPod</td>
                    <td class="py-3 pr-4 text-gray-600">On-demand GPU instances (A100, H100, etc.) with pod lifecycle control.</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-4 font-medium text-gray-900">Vast.ai</td>
                    <td class="py-3 pr-4 text-gray-600">Marketplace GPU rentals — typically the cheapest option for batch jobs.</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-4 font-medium text-gray-900">Fal</td>
                    <td class="py-3 pr-4 text-gray-600">Serverless inference for image, audio, and video models.</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-4 font-medium text-gray-900">Replicate</td>
                    <td class="py-3 pr-4 text-gray-600">Hosted model inference API (Stable Diffusion, LLaMA variants, custom models).</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-4 font-medium text-gray-900">Browser sidecar (Xvfb)</td>
                    <td class="py-3 pr-4 text-gray-600">Headful Chromium + patchright running inside an Xvfb virtual display. Use for anti-bot scenarios (Reddit, Cloudflare) that block headless browsers. Combine with a <a href="{{ route('docs.show', 'credentials') }}" class="text-primary-600 hover:underline">proxy credential</a> for residential-IP bypass.</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-4 font-medium text-gray-900">Bridge</td>
                    <td class="py-3 pr-4 text-gray-600">Use your own hardware via FleetQ Bridge (local LLMs, local agents).</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-4 font-medium text-gray-900">Platform</td>
                    <td class="py-3 pr-4 text-gray-600">Default shared compute for standard LLM API calls.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.callout type="tip">
        A unified <code class="font-mono text-xs">CostEstimator</code> projects spend per compute job across all
        providers before scheduling — useful for budget gating and plan-limit enforcement.
    </x-docs.callout>

    {{-- RunPod integration --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">RunPod integration</h2>
    <p class="mt-2 text-sm text-gray-600">
        Manage RunPod pods directly from FleetQ. Create, start, stop, and monitor GPU instances. Configure
        templates, volume mounts, and environment variables for each pod.
    </p>
    <x-docs.code lang="bash">
# Manage RunPod pods via MCP
runpod_manage(action: "list")
runpod_manage(action: "create", template_id: "...", gpu_type: "NVIDIA A100 80GB")</x-docs.code>
    <x-docs.callout type="info">
        RunPod credentials are stored as a team credential. Add your RunPod API key under
        <strong>Settings → Credentials</strong> before using the integration.
    </x-docs.callout>

    {{-- Bridge compute --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Bridge compute</h2>
    <p class="mt-2 text-sm text-gray-600">
        FleetQ Bridge connects your local hardware to the platform. Run Ollama, LM Studio, or any local
        LLM endpoint and use it as a compute backend for agents and experiments — at zero API cost.
    </p>
    <x-docs.callout type="tip">
        See the <a href="{{ route('docs.show', 'bridge') }}" class="text-primary-600 hover:underline">Bridge docs</a>
        for installation and setup instructions.
    </x-docs.callout>

    {{-- MCP tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-4 text-left font-semibold text-gray-700">Tool</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Purpose</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-3 pl-4 pr-4 font-mono text-xs text-gray-900">compute_manage</td>
                    <td class="py-3 pr-4 text-gray-600">List and inspect connected compute backends</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-4 font-mono text-xs text-gray-900">runpod_manage</td>
                    <td class="py-3 pr-4 text-gray-600">Create, start, stop, and monitor RunPod GPU instances</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Cost tracking --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Cost tracking</h2>
    <p class="mt-2 text-sm text-gray-600">
        GPU compute costs are tracked in the budget system alongside LLM API costs. RunPod spend is
        recorded in the credit ledger and counts toward team budget limits and alerts.
    </p>
    <x-docs.callout type="info">
        See the <a href="{{ route('docs.show', 'budget') }}" class="text-primary-600 hover:underline">Budget docs</a>
        for details on spend alerts, reservations, and plan limits.
    </x-docs.callout>
</x-layouts.docs>
