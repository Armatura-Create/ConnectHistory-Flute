<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Admin\Package\Screens\Concerns;

use DateTimeImmutable;
use DateTimeZone;
use Flute\Admin\Platform\Layouts\Filters;
use Flute\Admin\Platform\Layouts\LayoutFactory;
use Flute\Modules\ConnectHistory\Admin\Package\ConnectHistoryPackage;
use Flute\Modules\ConnectHistory\Services\Format;
use Flute\Modules\ConnectHistory\Services\HistoryRepository;
use Flute\Modules\ConnectHistory\Services\PlayerIdentityService;
use Flute\Modules\ConnectHistory\Services\SessionFilter;
use Throwable;

/**
 * Общее для всех экранов раздела: разбор фильтров, выбор сервера, права и форматирование.
 *
 * Смысл трейта — чтобы каждое чтение данных начиналось одинаково и после проверки
 * прав. Ни один экран не обращается к базе, пока не отработал bootHistory().
 */
trait ResolvesHistory
{
    protected ?HistoryRepository $history = null;

    protected ?SessionFilter $filter = null;

    /** @var array<int, string> id сервера панели -> название */
    protected array $serverOptions = [];

    protected bool $configured = false;

    /**
     * Вызывается первой строкой mount() каждого экрана.
     */
    protected function bootHistory(): void
    {
        $this->serverOptions = HistoryRepository::serverOptions();
        $this->filter = SessionFilter::fromArray(request()->query->all(), [
            'max_period_days' => (int) config('connecthistory.max_period_days', 365),
            'default_period_days' => (int) config('connecthistory.default_period_days', 7),
            'short_session_seconds' => (int) config('connecthistory.short_session_seconds', 60),
        ]);

        $this->history = HistoryRepository::for($this->filter->serverId);
        $this->configured = $this->history !== null;
    }

    /**
     * Экран «не настроено» с диагностикой: что модуль реально видит.
     *
     * Инструкция из четырёх шагов бесполезна тому, кто их уже выполнил, —
     * ему нужно знать, какое из подключений и почему не подошло.
     */
    protected function notConfiguredLayout()
    {
        return LayoutFactory::view('connecthistory::admin.not-configured', [
            'diagnostics' => HistoryRepository::diagnostics(),
        ]);
    }

    /** Персональные данные — отдельное право, а не отдельная колонка в вёрстке. */
    protected function canSeePii(): bool
    {
        try {
            return user()->can(ConnectHistoryPackage::PERMISSION_PII);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Базовые фильтры: сервер и период. Экраны добавляют свои поверх.
     */
    protected function baseFilters(): Filters
    {
        $filters = Filters::make();

        // Селектор нужен только когда есть из чего выбирать: при одной привязке
        // статистика и так считается по её серверу (HistoryRepository::for).
        //
        // Первый пункт называет сам фильтр, а не «Выберите опцию»: шаблон Filters
        // в ядре не выводит label для типа select (в отличие от buttonGroup, input
        // и checkbox), поэтому без описательного пункта выпадающий список
        // не сообщает, что именно он фильтрует. Значение '' = «не фильтровать».
        if (count($this->serverOptions) > 1) {
            $filters->select(
                'server',
                __('connecthistory.filters.server'),
                ['' => __('connecthistory.filters.all_servers')] + $this->serverOptions,
                $this->filter?->serverId,
                allowEmpty: false,
            );
        }

        return $filters->buttonGroup('period', __('connecthistory.filters.period'), [
            '1d' => __('connecthistory.periods.day'),
            '7d' => __('connecthistory.periods.week'),
            '30d' => __('connecthistory.periods.month'),
            '90d' => __('connecthistory.periods.quarter'),
            '180d' => __('connecthistory.periods.half_year'),
            '365d' => __('connecthistory.periods.year'),
        ], '7d')->dateRange('date', __('connecthistory.filters.dates'));
    }

    /**
     * Готовит метрики к отрисовке слоем Metric.
     *
     * Слои читают значения ИЗ Repository (`Arr::get` по точечному пути), а не через
     * Screen::get(): тот обслуживает вычисляемые свойства Yoyo и до слоёв не доходит.
     * Поэтому производные значения кладутся прямо в массив, иначе метрика молча
     * отрисуется пустой строкой.
     *
     * Форматирование делается ПОСЛЕ кеша: оно зависит от языка и пояса панели,
     * а кешируются сырые числа.
     *
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    protected function decorateMetrics(array $metrics): array
    {
        $metrics['avg_session_human'] = $this->humanDuration($metrics['avg_seconds'] ?? 0);
        $metrics['total_time_human'] = $this->humanDuration($metrics['total_seconds'] ?? 0);
        $metrics['retention_human'] = (float) ($metrics['retention'] ?? 0) . '%';

        return $metrics;
    }

    // --- личности игроков --------------------------------------------------

    /**
     * Одна пачка на страницу: собирает SteamID из строк и разрешает их разом.
     *
     * @param iterable<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    protected function identities(iterable $rows, string $idKey = 'steamid64', string $nameKey = 'nickname'): array
    {
        $ids = [];
        $names = [];

        foreach ($rows as $row) {
            $id = (string) ($row[$idKey] ?? '');

            if ($id === '') {
                continue;
            }

            $ids[] = $id;

            $name = $row[$nameKey] ?? null;

            if (is_scalar($name) && trim((string) $name) !== '') {
                $names[$id] = (string) $name;
            }
        }

        if ($ids === []) {
            return [];
        }

        return app(PlayerIdentityService::class)->resolveMany($ids, $names);
    }

    // --- форматирование ----------------------------------------------------

    /**
     * Время в базе — UTC. Пользователю показываем в поясе панели, иначе
     * «последний заход» врёт на несколько часов.
     */
    protected function toPanelTime(mixed $utc, string $format = 'd.m.Y H:i'): string
    {
        return Format::time($utc, $format);
    }

    protected function panelTimezone(): DateTimeZone
    {
        return Format::panelTimezone();
    }

    /** «2 ч 14 мин» вместо «8040». Форматирование общее с виджетами — см. Format. */
    protected function humanDuration(mixed $seconds): string
    {
        return Format::duration($seconds);
    }

    /**
     * Подпись состояния сессии. end_kind = 5 — не мусор, а факт аварийного
     * завершения сервера, и он должен быть виден.
     */
    protected function sessionState(mixed $endedAt, mixed $endKind): array
    {
        $kind = (int) $endKind;

        if ($kind === SessionFilter::END_KIND_STALE) {
            return ['label' => __('connecthistory.state.crashed'), 'color' => 'danger'];
        }

        if ($endedAt === null || $endedAt === '') {
            return ['label' => __('connecthistory.state.online'), 'color' => 'success'];
        }

        return match ($kind) {
            1 => ['label' => __('connecthistory.state.disconnect'), 'color' => 'muted'],
            2 => ['label' => __('connecthistory.state.map_change'), 'color' => 'muted'],
            3 => ['label' => __('connecthistory.state.shutdown'), 'color' => 'warning'],
            4 => ['label' => __('connecthistory.state.unload'), 'color' => 'warning'],
            default => ['label' => __('connecthistory.state.closed'), 'color' => 'muted'],
        };
    }

    /** Категории оси X из результата агрегирующего запроса. */
    protected function buckets(array $rows, string $format = 'd.m'): array
    {
        return array_map(function (array $row) use ($format): string {
            $bucket = (string) ($row['bucket'] ?? '');

            try {
                return (new DateTimeImmutable($bucket, new DateTimeZone('UTC')))
                    ->setTimezone($this->panelTimezone())
                    ->format($format);
            } catch (Throwable) {
                // Не дата — значит подпись уже готова (карта, страна, причина выхода)
                return $bucket;
            }
        }, $rows);
    }

    /** @return array<int, int|float> */
    protected function column(array $rows, string $key): array
    {
        return array_map(static fn (array $row) => 0 + ($row[$key] ?? 0), $rows);
    }

    /** Кеш вокруг агрегата: одинаковые фильтры не должны считаться дважды. */
    protected function cached(string $scope, callable $producer, ?int $ttl = null): mixed
    {
        $key = ($this->filter ?? SessionFilter::fromArray([]))->cacheKey($scope);
        $ttl ??= (int) config('connecthistory.cache.charts', 300);

        try {
            return cache()->callback($key, $producer, $ttl);
        } catch (Throwable $e) {
            // Считаем без кеша, чтобы раздел работал, но НЕ молча: неработающий
            // кеш означает запрос в базу на каждый заход, и заметить это можно
            // только по логу. Именно так пряталось двоеточие в ключе.
            logs()->warning('[ConnectHistory] Кеш недоступен, запрос выполняется напрямую: ' . $e->getMessage());

            return $producer();
        }
    }
}
