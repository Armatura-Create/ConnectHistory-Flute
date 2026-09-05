@php $fmt = \Flute\Modules\ConnectHistory\Services\Format::class; @endphp

{{--
    Адреса и мультиаккаунты. Без права admin.connecthistory.pii соответствующие
    запросы не выполняются вовсе — здесь нечего прятать, данных просто нет.
--}}
@if (!$withPii)
    <div class="card">
        <div class="card-body text-center" style="padding: 28px 24px;">
            <x-icon path="ph.regular.lock-simple" />
            <h4 style="margin-top: 10px;">{{ __('connecthistory.pii.hidden_title') }}</h4>
            <p class="text-muted" style="margin-top: 6px; font-size: 13px;">
                {{ __('connecthistory.pii.hidden_description') }}
            </p>
            <code class="ch-mono">admin.connecthistory.pii</code>
        </div>
    </div>
@else
    <div class="row gy-3">
        <div class="col-md-7">
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
                                     независимо от хеша, и тогда остаётся только подсеть --}}
                                <code class="ch-mono">{{ $row['player_ip'] ?: ($row['ip_subnet'] ?: '—') }}</code>

                                @if (!empty($row['country_iso']))
                                    <span class="ch-profile__chip">{{ $row['country_iso'] }}</span>
                                @endif
                                @if (!empty($row['city']))
                                    <span class="ch-block__city">{{ $row['city'] }}</span>
                                @endif
                            </span>

                            <span class="ch-block__meta">
                                {{ __('connecthistory.player.times_short', ['n' => (int) $row['sessions']]) }}
                                · {{ $fmt::time($row['first_seen'], 'd.m.Y') }}
                                — {{ $fmt::time($row['last_seen'], 'd.m.Y') }}
                            </span>
                        </div>
                    @empty
                        <p class="text-muted">{{ __('connecthistory.player.ip_empty') }}</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="card ch-block">
                <div class="card-header">
                    <h2>{{ __('connecthistory.player.alts_title') }}</h2>
                </div>

                <div class="card-body">
                    <p class="ch-hint">{{ __('connecthistory.player.alts_description') }}</p>

                    @forelse ($alts as $alt)
                        <div class="ch-block__row">
                            <a class="ch-block__main"
                               href="{{ url('/admin/connect-history/player/' . rawurlencode((string) $alt['steamid64'])) }}">
                                {{ $alt['nickname'] ?: $alt['steamid64'] }}
                            </a>
                            <span class="ch-block__meta">
                                {{ __('connecthistory.player.times_short', ['n' => (int) $alt['sessions']]) }}
                                · {{ $fmt::time($alt['last_seen'], 'd.m.Y') }}
                            </span>
                        </div>
                    @empty
                        <p class="text-muted">{{ __('connecthistory.player.alts_empty') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endif
