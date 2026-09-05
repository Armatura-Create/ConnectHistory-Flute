@props(['settings'])

<div class="row g-3">
    <div class="col-md-6">
        <x-admin::forms.field name="server_id" label="{{ __('connecthistory.settings.server_id') }}" required>
            <x-admin::fields.input type="number" name="server_id" id="server_id" min="1"
                value="{{ request()->input('server_id', $settings['server_id'] ?? 1) }}"
                placeholder="1" required />
        </x-admin::forms.field>
        <small class="text-muted">{{ __('connecthistory.settings.server_id_help') }}</small>
    </div>

    <div class="col-md-6">
        <x-admin::forms.field name="prefix" label="{{ __('connecthistory.settings.prefix') }}">
            <x-admin::fields.input name="prefix" id="prefix"
                value="{{ request()->input('prefix', $settings['prefix'] ?? 'ch_') }}"
                placeholder="ch_" />
        </x-admin::forms.field>
        <small class="text-muted">{{ __('connecthistory.settings.prefix_help') }}</small>
    </div>
</div>
