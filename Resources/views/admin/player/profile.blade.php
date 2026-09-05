@php $fmt = \Flute\Modules\ConnectHistory\Services\Format::class; @endphp

{{-- Шапка карточки: кто это и как он играет. Сводка за всё время, а не за период --}}
@php
    $kills = (int) ($summary['kills'] ?? 0);
    $deaths = (int) ($summary['deaths'] ?? 0);
    $rounds = (int) ($summary['rounds'] ?? 0);
@endphp

<div class="card ch-profile">
    <div class="card-body">
        <div class="ch-profile__head">
            <img class="ch-profile__avatar" src="{{ $fmt::avatar($identity['avatar'] ?? null) }}"
                 alt="" loading="lazy">

            <div class="ch-profile__ident">
                <span class="ch-profile__name">{{ $identity['name'] }}</span>
                <code class="ch-mono">{{ $steamid64 }}</code>

                <div class="ch-profile__links">
                    <a href="{{ $identity['url'] }}" target="_blank" rel="noopener">
                        <x-icon path="ph.regular.arrow-square-out" />
                        {{ __('connecthistory.player.open_steam') }}
                    </a>

                    @if (!empty($player['last_country']))
                        <span class="ch-profile__chip">{{ $player['last_country'] }}</span>
                    @endif

                    @if (!empty($summary['client_lang']))
                        <span class="ch-profile__chip">{{ $summary['client_lang'] }}</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="ch-profile__stats">
            <div class="ch-profile__stat">
                <span class="ch-profile__stat-value">
                    {{ $fmt::number($kills) }} /
                    {{ $fmt::number($deaths) }}
                </span>
                <span class="ch-profile__stat-label">{{ __('connecthistory.player.kd') }}</span>
            </div>

            <div class="ch-profile__stat">
                {{-- Деление на ноль: у игрока без смертей K/D не «бесконечность», а «нет данных» --}}
                <span class="ch-profile__stat-value">
                    {{ $deaths > 0 ? number_format($kills / $deaths, 2) : '—' }}
                </span>
                <span class="ch-profile__stat-label">{{ __('connecthistory.player.kd_ratio') }}</span>
            </div>

            <div class="ch-profile__stat">
                <span class="ch-profile__stat-value">
                    {{ $kills > 0 ? round((int) ($summary['headshots'] ?? 0) / $kills * 100) . '%' : '—' }}
                </span>
                <span class="ch-profile__stat-label">{{ __('connecthistory.player.hs') }}</span>
            </div>

            <div class="ch-profile__stat">
                <span class="ch-profile__stat-value">
                    {{ $fmt::number($summary['mvp'] ?? 0) }}
                </span>
                <span class="ch-profile__stat-label">{{ __('connecthistory.player.mvp') }}</span>
            </div>

            <div class="ch-profile__stat">
                <span class="ch-profile__stat-value">{{ $fmt::number($rounds) }}</span>
                <span class="ch-profile__stat-label">{{ __('connecthistory.player.rounds') }}</span>
            </div>

            <div class="ch-profile__stat">
                <span class="ch-profile__stat-value">
                    {{ ($summary['ping_avg'] ?? null) !== null ? (int) $summary['ping_avg'] : '—' }}
                </span>
                <span class="ch-profile__stat-label">{{ __('connecthistory.player.ping') }}</span>
            </div>

            @if ((int) ($summary['crashed'] ?? 0) > 0)
                <div class="ch-profile__stat">
                    <span class="ch-profile__stat-value ch-profile__stat-value--warn">
                        {{ (int) $summary['crashed'] }}
                    </span>
                    <span class="ch-profile__stat-label">{{ __('connecthistory.player.crashed') }}</span>
                </div>
            @endif
        </div>
    </div>
</div>
