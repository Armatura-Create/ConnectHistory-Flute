{{--
    Блок мультиаккаунтов скрыт не вёрсткой: без права admin.connecthistory.pii
    соответствующий запрос вообще не выполняется, а IP-колонки не попадают в SELECT.
--}}
<div class="card">
    <div class="card-body text-center" style="padding: 32px 24px;">
        <i class="ph ph-lock-simple" style="font-size: 32px; color: var(--text-400);"></i>

        <h5 style="margin-top: 12px;">{{ __('connecthistory.pii.hidden_title') }}</h5>

        <p class="text-muted" style="margin-top: 8px; font-size: 13px;">
            {{ __('connecthistory.pii.hidden_description') }}
        </p>

        <code style="font-size: 12px;">admin.connecthistory.pii</code>
    </div>
</div>
