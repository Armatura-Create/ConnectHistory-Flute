{{-- Виджет никогда не бывает молча пустым: пустая карточка не отличима от поломки --}}
<x-card class="ch-stat ch-stat--empty">
    <div class="ch-stat__body">
        <span class="ch-stat__icon">
            <x-icon path="ph.regular.info" />
        </span>
        <span class="ch-stat__text">
            <span class="ch-stat__label">{{ $message }}</span>
        </span>
    </div>
</x-card>
