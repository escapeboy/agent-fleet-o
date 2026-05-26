<div class="mx-auto max-w-3xl">
    <div class="mb-6">
        <a href="{{ route('skills.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Back to skills</a>
        <h1 class="mt-2 text-2xl font-semibold text-gray-900">Import Skill</h1>
        <p class="mt-1 text-sm text-gray-500">
            Paste a portable <a href="https://agentskills.io" target="_blank" rel="noopener" class="text-primary-600 hover:text-primary-800">agentskills.io</a>
            <code>SKILL.md</code> document (YAML frontmatter then Markdown instructions). FleetQ-specific
            fields are restored from <code>metadata.fleetq</code> when present.
        </p>
    </div>

    <form wire:submit="import" class="space-y-4">
        <x-form-textarea
            wire:model="skillMd"
            label="SKILL.md"
            mono
            rows="20"
            placeholder="---&#10;name: my-skill&#10;description: What the skill does and when to use it.&#10;---&#10;&#10;# Instructions&#10;..."
            :error="$errors->first('skillMd')"
        />

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('skills.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit"
                wire:loading.attr="disabled"
                class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="import">Import Skill</span>
                <span wire:loading wire:target="import">Importing…</span>
            </button>
        </div>
    </form>
</div>
