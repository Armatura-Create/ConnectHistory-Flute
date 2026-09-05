<div class="card">
    <div class="card-body text-center" style="padding: 48px 24px;">
        <i class="ph ph-user-minus" style="font-size: 48px; color: var(--text-400);"></i>

        <h4 style="margin-top: 16px;">{{ __('connecthistory.player.not_found') }}</h4>

        <p class="text-muted" style="max-width: 560px; margin: 12px auto 24px;">
            {{ __('connecthistory.player.not_found_description') }}
        </p>

        @if (!empty($steamid64))
            <code style="display: inline-block; margin-bottom: 20px;">{{ $steamid64 }}</code>
            <br>
        @endif

        <a href="{{ url('/admin/connect-history/players') }}" class="btn outline">
            {{ __('connecthistory.player.back_to_list') }}
        </a>
    </div>
</div>
