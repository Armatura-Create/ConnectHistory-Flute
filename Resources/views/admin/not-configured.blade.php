{{--
    Показывается, когда ни одно подключение не помечено модом ConnectHistory.
    Это не ошибка, а нормальное состояние свежей установки — поэтому здесь
    инструкция, а не сообщение об ошибке.
--}}
<div class="card">
    <div class="card-body text-center" style="padding: 48px 24px;">
        <i class="ph ph-plugs" style="font-size: 48px; color: var(--text-400);"></i>

        <h3 style="margin-top: 16px;">{{ __('connecthistory.setup.title') }}</h3>

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
    </div>
</div>
