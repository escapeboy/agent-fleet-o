@props(['components' => [], 'dataModel' => [], 'class' => ''])

@php
    $renderer = app(\App\Infrastructure\A2ui\A2uiRenderer::class);
    $html = $renderer->render($components, $dataModel);
@endphp

@if($html->toHtml() !== '')
    <div {{ $attributes->merge(['class' => 'a2ui-surface-container ' . $class]) }}>
        {!! $html !!}
    </div>
@endif
