@props([
    'entityType' => 'experiment',
    'entityId' => '',
])

@if($entityId !== '')
    <livewire:shared.fix-with-assistant
        :entity-type="$entityType"
        :entity-id="$entityId"
        :key="'fix-with-assistant-' . $entityType . '-' . $entityId"
    />
@endif
