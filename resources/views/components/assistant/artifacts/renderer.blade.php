@props(['payload' => [], 'messageId' => null, 'index' => 0])

@php
    $type = $payload['type'] ?? null;
    // First artifact in a message opens by default; the rest stay collapsed.
    $isOpen = $index === 0;
@endphp

@if($type === 'data_table')
    <x-assistant.artifacts.data-table :payload="$payload" :open="$isOpen" />
@elseif($type === 'chart')
    <x-assistant.artifacts.chart :payload="$payload" :open="$isOpen" />
@elseif($type === 'choice_cards')
    <x-assistant.artifacts.choice-cards :payload="$payload" :open="true" :messageId="$messageId" />
@elseif($type === 'form')
    <x-assistant.artifacts.form :payload="$payload" :open="true" :messageId="$messageId" />
@elseif($type === 'link_list')
    <x-assistant.artifacts.link-list :payload="$payload" :open="$isOpen" />
@elseif($type === 'code_diff')
    <x-assistant.artifacts.code-diff :payload="$payload" :open="$isOpen" />
@elseif($type === 'confirmation_dialog')
    <x-assistant.artifacts.confirmation-dialog :payload="$payload" :messageId="$messageId" />
@elseif($type === 'metric_card')
    <x-assistant.artifacts.metric-card :payload="$payload" />
@elseif($type === 'progress_tracker')
    <x-assistant.artifacts.progress-tracker :payload="$payload" />
@endif
