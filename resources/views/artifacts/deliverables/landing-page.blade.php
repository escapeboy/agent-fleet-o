@php
    /** @var \App\Models\Artifact $artifact */
    /** @var string $content */
    /** @var array|null $data */
@endphp

<div style="background: #f8fafc; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.85em; color: #475569;">
    <strong>Landing Page</strong> — rendered HTML from the deliverable. Script execution is disabled in preview.
</div>

<iframe
    srcdoc="{{ $content }}"
    sandbox="allow-same-origin"
    style="width: 100%; min-height: 600px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff;"
    loading="lazy"
    title="{{ $artifact->name }}"
></iframe>
