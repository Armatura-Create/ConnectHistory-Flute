@php $fmt = \Flute\Modules\ConnectHistory\Services\Format::class; @endphp

<div class="card ch-block">
    <div class="card-header">
        <h5 class="ch-block__title">{{ __('connecthistory.player.nicknames_title') }}</h5>
    </div>

    <div class="card-body">
        @forelse ($rows as $row)
            <div class="ch-block__row">
                <span class="ch-block__main">{{ $row['nickname'] }}</span>
                <span class="ch-block__meta">
                    {{ __('connecthistory.player.times_short', ['n' => (int) $row['times_seen']]) }}
                    · {{ $fmt::time($row['last_seen']) }}
                </span>
            </div>
        @empty
            <p class="text-muted">{{ __('connecthistory.player.nicknames_empty') }}</p>
        @endforelse
    </div>
</div>
