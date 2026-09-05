@php
    $colors = [
        'success' => 'var(--success)',
        'danger' => 'var(--danger)',
        'warning' => 'var(--warning)',
        'muted' => 'var(--text-400)',
    ];
    $color = $colors[$state['color']] ?? $colors['muted'];
@endphp

<span class="d-flex flex-column">
    <span style="color: {{ $color }}; font-weight: 500;">
        @if ($state['color'] === 'success')
            <i class="ph-fill ph-circle" style="font-size: 8px; vertical-align: middle;"></i>
        @endif
        {{ $state['label'] }}
    </span>

    @if (!empty($reason))
        <code style="font-size: 11px; color: var(--text-500);">{{ $reason }}</code>
    @endif
</span>
