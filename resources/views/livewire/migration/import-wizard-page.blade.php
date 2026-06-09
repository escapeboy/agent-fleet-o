<div class="mx-auto max-w-3xl space-y-6 p-4">
    {{-- Step indicator --}}
    <ol class="flex items-center gap-2 text-sm">
        @foreach (['Upload', 'Map columns', 'Result'] as $i => $label)
            <li class="flex items-center gap-2">
                <span @class([
                    'flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold',
                    'bg-primary-600 text-white' => $step >= $i + 1,
                    'bg-(--color-surface-alt) text-(--color-on-surface-muted)' => $step < $i + 1,
                ])>{{ $i + 1 }}</span>
                <span @class([
                    'font-medium' => $step === $i + 1,
                    'text-(--color-on-surface-muted)' => $step !== $i + 1,
                ])>{{ $label }}</span>
                @unless ($loop->last)
                    <span class="mx-1 text-(--color-on-surface-muted)">&rarr;</span>
                @endunless
            </li>
        @endforeach
    </ol>

    @if ($error)
        <div class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ $error }}
        </div>
    @endif

    {{-- Step 1: Upload + target importer --}}
    @if ($step === 1)
        <div class="space-y-4 rounded-xl border border-(--color-input-border) bg-(--color-surface) p-6">
            <h2 class="text-lg font-semibold text-(--color-on-surface)">Upload a CSV export</h2>
            <p class="text-sm text-(--color-on-surface-muted)">
                Export contacts from your previous tool (Salesforce, HubSpot, Intercom&hellip;) as CSV.
                We&rsquo;ll propose a column mapping for you to confirm.
            </p>

            <x-form-select wire:model="entityType" label="Import into" id="entityType"
                :error="$errors->first('entityType')">
                @foreach ($entityTypes as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </x-form-select>

            <div>
                <label class="mb-1 block text-sm font-medium text-(--color-on-surface)">CSV file</label>
                <input type="file" wire:model="file" accept=".csv,text/csv"
                    class="block w-full text-sm text-(--color-on-surface) file:mr-4 file:rounded-lg file:border-0 file:bg-primary-600 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-primary-700" />
                @error('file')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
                <div wire:loading wire:target="file" class="mt-1 text-xs text-(--color-on-surface-muted)">
                    Uploading&hellip;
                </div>
            </div>

            <div class="flex justify-end">
                <button type="button" wire:click="detect" wire:loading.attr="disabled" wire:target="detect,file"
                    class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="detect">Detect mapping</span>
                    <span wire:loading wire:target="detect">Analysing&hellip;</span>
                </button>
            </div>
        </div>
    @endif

    {{-- Step 2: Confirm / adjust mapping --}}
    @if ($step === 2)
        <div class="space-y-4 rounded-xl border border-(--color-input-border) bg-(--color-surface) p-6">
            <h2 class="text-lg font-semibold text-(--color-on-surface)">Confirm column mapping</h2>
            <p class="text-sm text-(--color-on-surface-muted)">
                Each source column maps to a FleetQ attribute. Leave a column <em>Unmapped</em> to drop it
                (text columns land in <code>metadata</code> via the importer).
            </p>

            @if ($run && ! empty($run->stats['warnings']))
                <ul class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-xs text-amber-800">
                    @foreach ($run->stats['warnings'] as $warning)
                        <li>&bull; {{ $warning }}</li>
                    @endforeach
                </ul>
            @endif

            <div class="space-y-2">
                @forelse ($mapping as $header => $target)
                    <div class="flex items-center gap-3" wire:key="map-{{ $loop->index }}">
                        <div class="w-1/2 truncate font-mono text-sm text-(--color-on-surface)" title="{{ $header }}">
                            {{ $header }}
                        </div>
                        <span class="text-(--color-on-surface-muted)">&rarr;</span>
                        <div class="w-1/2">
                            <x-form-select wire:model="mapping.{{ $header }}" compact>
                                <option value="">— Unmapped —</option>
                                @foreach ($supportedAttributes as $attr => $desc)
                                    <option value="{{ $attr }}">{{ $attr }}</option>
                                @endforeach
                            </x-form-select>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-(--color-on-surface-muted)">No columns were detected in this file.</p>
                @endforelse
            </div>

            <div class="flex items-center justify-between pt-2">
                <button type="button" wire:click="backToUpload"
                    class="rounded-lg border border-(--color-input-border) px-4 py-2.5 text-sm font-medium text-(--color-on-surface) hover:bg-(--color-surface-alt)">
                    Start over
                </button>
                <button type="button" wire:click="import" wire:loading.attr="disabled" wire:target="import"
                    class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="import">Run import</span>
                    <span wire:loading wire:target="import">Importing&hellip;</span>
                </button>
            </div>
        </div>
    @endif

    {{-- Step 3: Result --}}
    @if ($step === 3)
        <div class="space-y-4 rounded-xl border border-(--color-input-border) bg-(--color-surface) p-6">
            <h2 class="text-lg font-semibold text-(--color-on-surface)">Import {{ $run?->status->value }}</h2>

            @if ($run)
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    @foreach (['total' => 'Total', 'created' => 'Created', 'updated' => 'Updated', 'skipped' => 'Skipped', 'failed' => 'Failed'] as $key => $label)
                        <div class="rounded-lg bg-(--color-surface-alt) p-3 text-center">
                            <div class="text-2xl font-semibold text-(--color-on-surface)">{{ $run->stats[$key] ?? 0 }}</div>
                            <div class="text-xs text-(--color-on-surface-muted)">{{ $label }}</div>
                        </div>
                    @endforeach
                </div>

                @if (! empty($run->errors))
                    <details class="rounded-lg border border-(--color-input-border) p-3 text-sm">
                        <summary class="cursor-pointer font-medium text-(--color-on-surface)">
                            {{ count($run->errors) }} row issue(s)
                        </summary>
                        <ul class="mt-2 space-y-1 text-xs text-(--color-on-surface-muted)">
                            @foreach (array_slice($run->errors, 0, 50) as $err)
                                <li>Row {{ $err['row'] ?? '?' }}: {{ $err['message'] ?? '' }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            @endif

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" wire:click="backToUpload"
                    class="rounded-lg border border-(--color-input-border) px-4 py-2.5 text-sm font-medium text-(--color-on-surface) hover:bg-(--color-surface-alt)">
                    Import another file
                </button>
                <a href="{{ route('contacts.index') }}"
                    class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                    View contacts
                </a>
            </div>
        </div>
    @endif
</div>
