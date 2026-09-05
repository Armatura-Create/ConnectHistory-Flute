@php $fmt = \Flute\Modules\ConnectHistory\Services\Format::class; @endphp

<div class="card ch-block">
    <div class="card-header">
        <h5 class="ch-block__title">{{ __('connecthistory.player.alts_title') }}</h5>
    </div>

    <div class="card-body">
        <p class="ch-hint">{{ __('connecthistory.player.alts_description') }}</p>

        @forelse ($rows as $row)
            <div class="ch-block__row">
                <a class="ch-block__main"
                   href="{{ url('/admin/connect-history/player/' . rawurlencode((string) $row['steamid64'])) }}">
                    {{ $row['nickname'] ?: $row['steamid64'] }}
                </a>
                <span class="ch-block__meta">
                    {{ __('connecthistory.player.times_short', ['n' => (int) $row['sessions']]) }}
                    · {{ $fmt::time($row['last_seen'], 'd.m.Y') }}
                </span>
            </div>
        @empty
            <p class="text-muted">{{ __('connecthistory.player.alts_empty') }}</p>
        @endforelse
    </div>
</div>
