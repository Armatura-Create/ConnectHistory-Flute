<form>
    <x-forms.field class="mb-3">
        <x-forms.label for="metric">{{ __('connecthistory.widget.settings.metric') }}</x-forms.label>
        <x-fields.select name="metric" id="metric">
            @foreach ($metrics as $metric)
                <option value="{{ $metric }}" {{ $settings['metric'] === $metric ? 'selected' : '' }}>
                    {{ __('connecthistory.widget.metrics.' . $metric) }}
                </option>
            @endforeach
        </x-fields.select>
    </x-forms.field>

    <x-forms.field class="mb-3">
        <x-forms.label for="server">{{ __('connecthistory.widget.settings.server') }}</x-forms.label>
        <x-fields.select name="server" id="server">
            <option value="" {{ $settings['server'] === '' ? 'selected' : '' }}>
                {{ __('connecthistory.filters.all_servers') }}
            </option>
            @foreach ($servers as $id => $name)
                <option value="{{ $id }}" {{ (string) $settings['server'] === (string) $id ? 'selected' : '' }}>
                    {{ $name }}
                </option>
            @endforeach
        </x-fields.select>
    </x-forms.field>

    <x-forms.field class="mb-3">
        <x-forms.label for="period">{{ __('connecthistory.widget.settings.period') }}</x-forms.label>
        <x-fields.select name="period" id="period">
            @foreach ($periods as $period)
                <option value="{{ $period }}" {{ $settings['period'] === $period ? 'selected' : '' }}>
                    {{ __('connecthistory.widget.periods.' . $period) }}
                </option>
            @endforeach
        </x-fields.select>
        <small class="text-muted">{{ __('connecthistory.widget.settings.period_help') }}</small>
    </x-forms.field>

    <x-forms.field class="mb-3">
        <x-forms.label for="label">{{ __('connecthistory.widget.settings.label') }}</x-forms.label>
        <x-fields.input name="label" id="label" value="{{ $settings['label'] }}"
            placeholder="{{ __('connecthistory.widget.settings.label_placeholder') }}" />
    </x-forms.field>

    <x-forms.field class="mb-3">
        <x-forms.label for="icon">{{ __('connecthistory.widget.settings.icon') }}</x-forms.label>
        <x-fields.input name="icon" id="icon" value="{{ $settings['icon'] }}"
            placeholder="ph.regular.users-three" />
        <small class="text-muted">{{ __('connecthistory.widget.settings.icon_help') }}</small>
    </x-forms.field>

    <x-forms.field>
        <label class="d-flex align-items-center gap-2">
            <input type="checkbox" name="show_period" value="1" {{ $settings['show_period'] ? 'checked' : '' }} />
            <span>{{ __('connecthistory.widget.settings.show_period') }}</span>
        </label>
    </x-forms.field>
</form>
