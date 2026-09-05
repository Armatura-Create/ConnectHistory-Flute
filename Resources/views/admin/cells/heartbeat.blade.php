{{--
    Снимок онлайна плагин делает раз в OnlineSnapshotIntervalSeconds.
    Разрыв больше порога — это не «нет данных», а «сервер молчал».
--}}
@if ($row['last_snapshot'] === null)
    <span class="text-muted">{{ __('connecthistory.servers.no_snapshots') }}</span>
@elseif ($row['silent'])
    <span style="color: var(--warning);" data-tooltip="{{ __('connecthistory.servers.silent_help') }}">
        <i class="ph ph-warning-circle"></i>
        {{ $row['last_snapshot_human'] }}
    </span>
@else
    <span style="color: var(--success);">
        <i class="ph-fill ph-circle" style="font-size: 8px; vertical-align: middle;"></i>
        {{ $row['last_snapshot_human'] }}
    </span>
@endif
