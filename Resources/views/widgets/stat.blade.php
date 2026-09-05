<x-card class="ch-stat">
    <div class="ch-stat__body">
        <span class="ch-stat__icon">
            <x-icon :path="$icon" />
        </span>

        <span class="ch-stat__text">
            <span class="ch-stat__value">{{ $value }}</span>
            <span class="ch-stat__label">{{ $label }}</span>

            @if ($period || $server)
                <span class="ch-stat__meta">
                    @if ($server){{ $server }}@endif
                    @if ($server && $period) · @endif
                    @if ($period){{ $period }}@endif
                </span>
            @endif
        </span>
    </div>
</x-card>
