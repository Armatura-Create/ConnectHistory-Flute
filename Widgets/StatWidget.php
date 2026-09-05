<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Widgets;

use Flute\Core\Modules\Page\Widgets\AbstractWidget;
use Flute\Modules\ConnectHistory\Services\Format;
use Flute\Modules\ConnectHistory\Services\HistoryRepository;
use Flute\Modules\ConnectHistory\Services\SessionFilter;
use Throwable;

/**
 * Одно число из истории подключений: показатель, сервер и период выбираются
 * в настройках виджета.
 *
 * Один класс вместо девяти отдельных виджетов: показатели отличаются только
 * ключом и форматированием, а всё остальное — сервер, период, подпись, иконка,
 * кеш — у них общее.
 *
 * ПРО КЕШ. Виджет живёт на публичной странице, то есть считается на каждый
 * заход посетителя. Без кеша это прямой удар в базу с чужого сервера. Поэтому
 * кеша два уровня:
 *   1) getCacheTime() — Flute кеширует ГОТОВЫЙ HTML виджета;
 *   2) cache()->callback() вокруг запроса — на случай, если HTML-кеш выключен
 *      или виджетов с одинаковыми настройками несколько.
 * Ключ включает показатель, сервер и период, поэтому одинаково настроенные
 * виджеты на разных страницах делят один результат.
 *
 * Персональных данных здесь нет ни в одном показателе: виджет публичный.
 */
class StatWidget extends AbstractWidget
{
    /** Показатель -> как его считать и как форматировать. */
    public const METRICS = [
        'online_now' => 'number',
        'players' => 'number',
        'sessions' => 'number',
        'newcomers' => 'number',
        'peak_online' => 'number',
        'avg_session' => 'duration',
        'total_hours' => 'hours',
        'retention' => 'percent',
        'crashes' => 'number',
    ];

    /** Онлайн меняется каждую минуту, остальное — медленно. */
    private const TTL_LIVE = 30;

    private const TTL_AGGREGATE = 300;

    public function getName(): string
    {
        return 'connecthistory.widget.name';
    }

    public function getDescription(): string
    {
        return 'connecthistory.widget.description';
    }

    public function getIcon(): string
    {
        return 'ph.regular.chart-line-up';
    }

    /**
     * Категории виджетов переводятся ядром по ключу page.categories.<категория>,
     * поэтому выдумывать свою нельзя — получится «page.categories.statistics»
     * вместо названия. Существующие: general, users, user, content, media, other,
     * payments, admin, stats, system, social.
     */
    public function getCategory(): string
    {
        return 'stats';
    }

    public function getDefaultWidth(): int
    {
        return 3;
    }

    public function getMinWidth(): int
    {
        return 2;
    }

    public function getSettings(): array
    {
        return [
            'metric' => 'online_now',
            'server' => '',
            'period' => '30d',
            'label' => '',
            'icon' => 'ph.regular.users-three',
            'show_period' => true,
        ];
    }

    public function hasSettings(): bool
    {
        return true;
    }

    public function getCacheTime(): int
    {
        return self::TTL_AGGREGATE;
    }

    public function renderSettingsForm(array $settings): string|bool
    {
        return view('connecthistory::widgets.settings', [
            'settings' => array_merge($this->getSettings(), $settings),
            'metrics' => array_keys(self::METRICS),
            'periods' => array_keys(SessionFilter::PERIODS),
            'servers' => $this->serverOptions(),
        ])->render();
    }

    public function saveSettings(array $input): array
    {
        $metric = (string) ($input['metric'] ?? 'online_now');
        $period = (string) ($input['period'] ?? '30d');

        return [
            // Значения из формы — недоверенный вход: показатель и период
            // подставляются в ключ кеша и в выбор запроса.
            'metric' => isset(self::METRICS[$metric]) ? $metric : 'online_now',
            'period' => isset(SessionFilter::PERIODS[$period]) ? $period : '30d',
            'server' => is_numeric($input['server'] ?? null) ? (string) (int) $input['server'] : '',
            'label' => mb_substr(trim((string) ($input['label'] ?? '')), 0, 64),
            'icon' => mb_substr(trim((string) ($input['icon'] ?? '')), 0, 64),
            'show_period' => filter_var($input['show_period'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * ВАЖНО: этот метод не имеет права ни бросить исключение, ни вернуть null.
     *
     * WidgetController::saveSettings() вызывает render() СРАЗУ после сохранения
     * и кладёт результат в JSON-ответ. Исключение там перехватывается и вместо
     * JSON возвращается HTML формы — для фронтенда это выглядит как «сохранение
     * не работает». null же превращается в пустой виджет без единого слова
     * о причине.
     *
     * Поэтому любая проблема показывается карточкой с текстом, а не молчанием.
     */
    public function render(array $settings): ?string
    {
        try {
            return $this->renderCard($settings);
        } catch (Throwable $e) {
            logs()->error('[ConnectHistory] Виджет не отрисовался: ' . $e->getMessage());

            return $this->placeholder(__('connecthistory.widget.error'));
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderCard(array $settings): string
    {
        $settings = array_merge($this->getSettings(), $settings);
        $metric = isset(self::METRICS[$settings['metric']]) ? (string) $settings['metric'] : 'online_now';
        $period = isset(SessionFilter::PERIODS[$settings['period']]) ? (string) $settings['period'] : '30d';
        $serverId = is_numeric($settings['server']) ? (int) $settings['server'] : null;

        $repository = HistoryRepository::for($serverId);

        // Ноль означал бы «данных нет», а не «модуль не настроен», поэтому
        // вместо числа показывается причина.
        if ($repository === null) {
            return $this->placeholder(__('connecthistory.widget.not_configured'));
        }

        $filter = SessionFilter::fromArray(
            ['period' => $period, 'server' => $serverId],
            ['max_period_days' => (int) config('connecthistory.max_period_days', 365)]
        );

        $ttl = $metric === 'online_now' ? self::TTL_LIVE : self::TTL_AGGREGATE;
        // Точки, а не двоеточия: в PSR-6 символы {}()/\@: зарезервированы,
        // и кеш на таком ключе бросает исключение вместо кеширования.
        $key = 'connecthistory.widget.' . $metric . '.' . ($serverId ?? 'all') . '.' . $period;

        try {
            $value = cache()->callback($key, fn () => $this->compute($repository, $filter, $metric), $ttl);
        } catch (Throwable $e) {
            logs()->warning('[ConnectHistory] Виджет не смог получить данные: ' . $e->getMessage());

            return $this->placeholder(__('connecthistory.widget.unavailable'));
        }

        return view('connecthistory::widgets.stat', [
            'value' => $this->format($value, self::METRICS[$metric]),
            'label' => $settings['label'] !== ''
                ? $settings['label']
                : __('connecthistory.widget.metrics.' . $metric),
            'icon' => $settings['icon'] !== '' ? $settings['icon'] : 'ph.regular.chart-line-up',
            'period' => $settings['show_period'] && $metric !== 'online_now'
                ? __('connecthistory.widget.periods.' . $period)
                : null,
            'server' => $serverId !== null ? ($this->serverOptions()[$serverId] ?? null) : null,
        ])->render();
    }

    /**
     * Сырое значение показателя.
     *
     * Все агрегаты берутся из overviewMetrics() одним заходом, а не девятью
     * отдельными запросами: результат всё равно кешируется целиком, а поддерживать
     * один набор запросов проще, чем девять почти одинаковых.
     */
    private function compute(HistoryRepository $repository, SessionFilter $filter, string $metric): float|int
    {
        if ($metric === 'online_now') {
            $counts = $repository->onlineCounts();

            return array_sum(array_map('intval', $counts));
        }

        $metrics = $repository->overviewMetrics($filter);

        return match ($metric) {
            'players' => (int) ($metrics['players'] ?? 0),
            'sessions' => (int) ($metrics['sessions'] ?? 0),
            'newcomers' => (int) ($metrics['newcomers'] ?? 0),
            'peak_online' => (int) ($metrics['peak_online'] ?? 0),
            'avg_session' => (int) ($metrics['avg_seconds'] ?? 0),
            'total_hours' => (int) ($metrics['total_seconds'] ?? 0),
            'retention' => (float) ($metrics['retention'] ?? 0),
            'crashes' => (int) ($metrics['crashed'] ?? 0),
            default => 0,
        };
    }

    private function format(float|int $value, string $as): string
    {
        return match ($as) {
            'duration' => Format::duration($value),
            'hours' => Format::hours($value),
            'percent' => Format::percent($value),
            default => Format::number($value),
        };
    }

    /**
     * Карточка с объяснением вместо числа. Ровно то, чего не хватает, когда
     * виджет «просто пустой»: видно, что именно пошло не так.
     */
    private function placeholder(string $message): string
    {
        return view('connecthistory::widgets.placeholder', ['message' => $message])->render();
    }

    /** @return array<int, string> */
    private function serverOptions(): array
    {
        try {
            return HistoryRepository::serverOptions();
        } catch (Throwable) {
            return [];
        }
    }
}
