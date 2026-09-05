@php $fmt = \Flute\Modules\ConnectHistory\Services\Format::class; @endphp

@php
    // Доля считается от самой популярной карты, а не от общего времени:
    // так полоса читается как «во что играет чаще всего», а не тонет в процентах
    $top = collect($rows)->max(fn ($r) => (int) $r['total_seconds']) ?: 1;
@endphp

<div class="card ch-block">
    <div class="card-header">
        <h5 class="ch-block__title">{{ __('connecthistory.player.maps_title') }}</h5>
    </div>

    <div class="card-body">
        @forelse ($rows as $row)
            <div class="ch-block__row ch-block__row--bar">
                <span class="ch-block__main">{{ $row['bucket'] }}</span>
                <span class="ch-block__meta">
                    {{ $fmt::duration($row['total_seconds']) }}
                </span>
                <span class="ch-block__bar">
                    <span style="width: {{ max(2, round((int) $row['total_seconds'] / $top * 100)) }}%"></span>
                </span>
            </div>
        @empty
            <p class="text-muted">{{ __('connecthistory.player.maps_empty') }}</p>
        @endforelse
    </div>
</div>
