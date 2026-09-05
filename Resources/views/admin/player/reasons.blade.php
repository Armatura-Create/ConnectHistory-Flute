@php $fmt = \Flute\Modules\ConnectHistory\Services\Format::class; @endphp

<div class="card ch-block">
    <div class="card-header">
        <h5 class="ch-block__title">{{ __('connecthistory.player.reasons_title') }}</h5>
    </div>

    <div class="card-body">
        @forelse ($rows as $row)
            <div class="ch-block__row">
                <code class="ch-mono ch-block__main">{{ $row['bucket'] }}</code>
                <span class="ch-block__meta">
                    {{ __('connecthistory.player.times_short', ['n' => (int) $row['sessions']]) }}
                    · {{ $fmt::time($row['last_seen']) }}
                </span>
            </div>
        @empty
            <p class="text-muted">{{ __('connecthistory.player.reasons_empty') }}</p>
        @endforelse
    </div>
</div>
