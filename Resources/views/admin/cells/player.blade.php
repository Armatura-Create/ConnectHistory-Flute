{{--
    Карточка игрока в таблице.

    $row['identity'] заполняет PlayerIdentityService и гарантирует непустое имя
    даже без Steam и без пользователя на сайте. Поэтому здесь нет ни одной
    проверки «а вдруг null» вокруг аватара — её место в сервисе.
--}}
@php
    $identity = $row['identity'] ?? null;
    $steamId = (string) ($row['steamid64'] ?? '');
    $name = $identity['name'] ?? ($row['nickname'] ?? $row['last_nickname'] ?? $steamId);
    $href = $link ?? ($identity['url'] ?? '#');

    // Аватар из Steam — абсолютный URL, у пользователя сайта — относительный путь.
    // Format::avatar разворачивает второй и подставляет дефолтный, если нет ничего.
    $avatar = \Flute\Modules\ConnectHistory\Services\Format::avatar($identity['avatar'] ?? null);
@endphp

<div class="d-flex align-items-center gap-2">
    <img src="{{ $avatar }}" alt="" width="28" height="28"
         style="border-radius: 6px; object-fit: cover; flex-shrink: 0;" loading="lazy">

    <span class="d-flex flex-column" style="min-width: 0;">
        <a href="{{ $href }}" class="text-truncate" style="font-weight: 500;">{{ $name }}</a>

        @if ($steamId !== '')
            <code style="font-size: 11px; color: var(--text-500);">{{ $steamId }}</code>
        @endif
    </span>
</div>
