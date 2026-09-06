@php $fmt = \Flute\Modules\ConnectHistory\Services\Format::class; @endphp

<div class="card ch-block">
    <div class="card-header">
        <h5 class="ch-block__title">{{ __('connecthistory.player.servers_title') }}<x-popover :content="__('connecthistory.help.player_servers')" /></h5>
    </div>

    <div class="card-body">
        @forelse ($rows as $row)
            <div class="ch-block__row">
                <span class="ch-block__main">{{ $row['server_name'] }}</span>
                <span class="ch-block__meta">
                    {{ $fmt::duration($row['total_seconds']) }}
                    · {{ __('connecthistory.player.times_short', ['n' => (int) $row['sessions']]) }}
                    · {{ $fmt::time($row['last_seen']) }}
                </span>
            </div>
        @empty
            <p class="text-muted">{{ __('connecthistory.player.servers_empty') }}</p>
        @endforelse
    </div>
</div>
