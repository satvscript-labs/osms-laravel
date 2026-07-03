@props([
    'label' => '',
    'value' => '',
    'hint' => null,
    'icon' => 'bi-circle',
    'tone' => 'default', // default | primary | amber
    'href' => null,
])

@php
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => "metric-card tone-$tone card card-lift border-0 shadow-sm rounded-4 text-decoration-none text-reset h-100"]) }}>
    <div class="card-body d-flex align-items-start justify-content-between p-3 p-md-4">
        <div class="min-w-0">
            <p class="text-muted-foreground mb-1 text-xs">{{ $label }}</p>
            <p class="h4 fw-semibold font-display mb-0">{{ $value }}</p>
            @if($hint)
                <p class="text-muted-foreground mb-0 mt-1 text-2xs">{{ $hint }}</p>
            @endif
        </div>
        <span class="metric-icon flex-shrink-0"><i class="bi {{ $icon }}"></i></span>
    </div>
</{{ $tag }}>
