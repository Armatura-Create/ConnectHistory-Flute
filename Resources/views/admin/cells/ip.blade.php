{{-- Адрес зеркала подписывается прямо в ячейке: без этого строка выглядит как
     чужой человек из чужой страны, а на деле это собственный прокси. --}}
<span class="ch-ip">
    <code class="ch-mono">{{ $ip ?: '—' }}</code>

    @if ($mirror)
        <span class="ch-chip ch-chip--mirror"
            title="{{ __('connecthistory.mirror.tooltip') }}">{{ $mirror }}</span>
    @endif
</span>
