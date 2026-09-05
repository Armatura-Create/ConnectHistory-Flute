{{--
    Показывается, когда ни одно подключение не помечено модом ConnectHistory.
    Это не ошибка, а нормальное состояние свежей установки — поэтому здесь
    инструкция, а не сообщение об ошибке.
--}}
<div class="card">
    <div class="card-body text-center" style="padding: 48px 24px;">
        <i class="ph ph-plugs" style="font-size: 48px; color: var(--text-400);"></i>

        <h4 style="margin-top: 16px;">{{ __('connecthistory.setup.title') }}</h4>

        <p class="text-muted" style="max-width: 620px; margin: 12px auto 24px;">
            {{ __('connecthistory.setup.description') }}
        </p>

        <ol class="text-start text-muted" style="max-width: 620px; margin: 0 auto 24px; line-height: 1.9;">
            <li>{{ __('connecthistory.setup.step_1') }}</li>
            <li>{{ __('connecthistory.setup.step_2') }}</li>
            <li>{{ __('connecthistory.setup.step_3') }}</li>
            <li>{{ __('connecthistory.setup.step_4') }}</li>
        </ol>

        <a href="{{ url('/admin/servers') }}" class="btn primary">
            <i class="ph ph-hard-drives"></i>
            {{ __('connecthistory.setup.open_servers') }}
        </a>

        {{-- Инструкция бесполезна тому, кто её уже выполнил: показываем, что модуль видит --}}
        @if (($diagnostics['total'] ?? 0) > 0)
            <div style="max-width: 620px; margin: 28px auto 0; padding-top: 20px; border-top: 1px solid var(--transp-05);">
                <p class="text-muted" style="font-size: 13px;">
                    {{ __('connecthistory.setup.found', [
                        'total' => $diagnostics['total'],
                        'usable' => $diagnostics['usable'],
                    ]) }}
                </p>

                @if (!empty($diagnostics['problems']))
                    <ul class="text-start" style="font-size: 13px; color: var(--warning); line-height: 1.8;">
                        @foreach ($diagnostics['problems'] as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </div>
</div>
