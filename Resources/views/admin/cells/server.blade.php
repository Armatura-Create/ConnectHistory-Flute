{{--
    0.0.0.0 — это адрес привязки сокета, а не адрес сервера: процесс не знает
    своего публичного адреса. Показываем это прямо, а не выводим мусор как факт.
--}}
<span class="d-flex flex-column">
    <span style="font-weight: 500;">
        {{ $row['hostname'] !== '' ? $row['hostname'] : __('connecthistory.servers.no_hostname') }}
    </span>

    @if ($row['address_broken'])
        <span style="font-size: 11px; color: var(--warning);" data-tooltip="{{ __('connecthistory.servers.address_help') }}">
            <i class="ph ph-warning"></i>
            {{ __('connecthistory.servers.address_unknown') }}
        </span>
    @else
        <code style="font-size: 11px; color: var(--text-500);">{{ $row['address'] }}</code>
    @endif
</span>
