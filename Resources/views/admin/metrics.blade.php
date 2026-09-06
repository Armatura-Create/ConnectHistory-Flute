{{--
    Метрики со знаком вопроса.

    Слой Metric платформы подсказок не умеет: он экранирует подпись и не имеет
    ни popover(), ни слота. Поэтому здесь своя разметка — но на КЛАССАХ ПАНЕЛИ
    (metrics, metric__label и прочие), чтобы вид совпадал с остальными экранами
    и не пришлось тащить копию их стилей.

    Каждый элемент: ['label' => ..., 'value' => ..., 'icon' => ..., 'help' => ...].
    help необязателен — знак вопроса появляется только там, где есть что пояснить.
--}}
<div class="metrics">
    <div class="metrics__grid">
        @foreach ($items as $item)
            <div class="metrics__item">
                <div class="metric">
                    <div class="metric__header">
                        @if (!empty($item['icon']))
                            <div class="metric__icon">
                                <x-icon path="ph.regular.{{ $item['icon'] }}" class="metric__icon-svg" />
                            </div>
                        @endif

                        <span class="metric__label">
                            {{ $item['label'] }}
                            @if (!empty($item['help']))
                                <x-popover :content="$item['help']" />
                            @endif
                        </span>
                    </div>

                    <div class="metric__body">
                        <div class="metric__row">
                            <div class="metric__number">{{ $item['value'] }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
