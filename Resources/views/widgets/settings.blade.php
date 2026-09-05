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

    {{--
        type="icon" — штатное поле панели: даёт поиск по иконкам и предпросмотр
        выбранной. Оно же обновляет предпросмотр при выборе из списка, но НЕ при
        ручном вводе имени — этот пробел закрывает обработчик ниже.

        Обработчик написан атрибутом oninput, а не отдельным <script>: форма
        настроек вставляется в модалку через innerHTML, а вставленный так <script>
        браузер не выполняет. Атрибут работает в любом случае.
    --}}
    <x-forms.field class="mb-3">
        <x-forms.label for="icon">{{ __('connecthistory.widget.settings.icon') }}</x-forms.label>

        <div class="ch-icon-field">
            <span class="ch-icon-field__preview" data-ch-icon-preview>
                @if ($settings['icon'])
                    {!! app(\Flute\Core\Modules\Icons\Services\IconFinder::class)->loadFile($settings['icon']) !!}
                @endif
            </span>

            <x-fields.input type="icon" name="icon" id="icon" value="{{ $settings['icon'] }}"
                placeholder="ph.regular.users-three"
                oninput="(function(i){var w=i.closest('.ch-icon-field');if(!w)return;var p=w.querySelector('[data-ch-icon-preview]');if(!p)return;clearTimeout(i._chT);i._chT=setTimeout(function(){var v=(i.value||'').trim();if(!v){p.innerHTML='';return;}fetch('{{ url('/admin/api/icons/render') }}?path='+encodeURIComponent(v)).then(function(r){return r.ok?r.text():'';}).then(function(svg){p.innerHTML=svg||'';}).catch(function(){p.innerHTML='';});},350);})(this)" />
        </div>

        <small class="text-muted">{{ __('connecthistory.widget.settings.icon_help') }}</small>
    </x-forms.field>

    <x-forms.field>
        <label class="d-flex align-items-center gap-2">
            <input type="checkbox" name="show_period" value="1" {{ $settings['show_period'] ? 'checked' : '' }} />
            <span>{{ __('connecthistory.widget.settings.show_period') }}</span>
        </label>
    </x-forms.field>
</form>
