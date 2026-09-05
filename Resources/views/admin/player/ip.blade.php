@php $fmt = \Flute\Modules\ConnectHistory\Services\Format::class; @endphp

<div class="card ch-block">
    <div class="card-header">
        <h2>{{ __('connecthistory.player.ip_title') }}</h2>
    </div>

    <div class="card-body">
        <p class="ch-hint">{{ __('connecthistory.player.ip_description') }}</p>

        @forelse ($rows as $row)
            <div class="ch-block__row">
                <span class="ch-block__main">
                    {{-- Полного адреса может не быть: Collect.PlayerIp выключается
                         независимо от хеша, тогда остаётся только подсеть --}}
                    <code class="ch-mono">{{ $row['player_ip'] ?: ($row['ip_subnet'] ?: '—') }}</code>

                    @if (!empty($row['country_iso']))
                        <span class="ch-chip">{{ $row['country_iso'] }}</span>
                    @endif
                    @if (!empty($row['city']))
                        <span class="ch-block__city">{{ $row['city'] }}</span>
                    @endif
                </span>

                <span class="ch-block__meta">
                    {{ __('connecthistory.player.times_short', ['n' => (int) $row['sessions']]) }}
                    · {{ $fmt::time($row['first_seen'], 'd.m.Y') }} — {{ $fmt::time($row['last_seen'], 'd.m.Y') }}
                </span>
            </div>
        @empty
            <p class="text-muted">{{ __('connecthistory.player.ip_empty') }}</p>
        @endforelse
    </div>
</div>
