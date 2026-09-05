<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Admin\Package\Screens;

use Flute\Admin\Platform\Fields\Tab;
use Flute\Admin\Platform\Fields\TD;
use Flute\Admin\Platform\Layouts\LayoutFactory;
use Flute\Admin\Platform\Screen;
use Flute\Modules\ConnectHistory\Admin\Package\ConnectHistoryPackage;
use Flute\Modules\ConnectHistory\Admin\Package\Screens\Concerns\ResolvesHistory;

/**
 * Обзор: метрики и восемь разрезов данных по вкладкам.
 *
 * Вкладка = один агрегирующий запрос, чьё имя совпадает с подписью. Между
 * запросом и графиком нет вычислений, где смысл мог бы разойтись с подписью —
 * предыдущий модуль рисовал накопительную сумму под заголовком «История
 * подключений» (docs/AUTOPSY.md №7).
 *
 * Считается ТОЛЬКО активная вкладка: восемь запросов на каждое открытие страницы
 * не нужны, если человек смотрит один график.
 */
class OverviewScreen extends Screen
{
    use ResolvesHistory;

    private const TABS_SLUG = 'ch-overview';

    private const TAB_ONLINE = 'online';
    private const TAB_JOINS = 'joins';
    private const TAB_HEATMAP = 'heatmap';
    private const TAB_NEWCOMERS = 'newcomers';
    private const TAB_MAPS = 'maps';
    private const TAB_REASONS = 'reasons';
    private const TAB_GEO = 'geo';
    private const TAB_CRASHES = 'crashes';

    public ?string $name = null;

    public ?string $description = null;

    public ?string $permission = ConnectHistoryPackage::PERMISSION_VIEW;

    /** @var array<string, mixed> */
    public array $metrics = [];

    /**
     * Данные активной вкладки в формате ApexCharts.
     *
     * Тип намеренно широкий: линейные и столбчатые графики ждут серии вида
     * [{name, data}], а donut — плоский массив чисел с подписями в $labels.
     *
     * @var array<int, mixed>
     */
    public array $series = [];

    /** @var array<int, string> */
    public array $labels = [];

    /** @var array<int, array<string, mixed>> */
    public array $crashes = [];

    /** @var array<int, array<string, mixed>> */
    public array $geoRows = [];

    protected string $activeTab = self::TAB_ONLINE;

    public function mount(): void
    {
        $this->name = __('connecthistory.overview.title');
        $this->description = __('connecthistory.overview.description');

        breadcrumb()
            ->add(__('def.admin_panel'), (string) url('/admin'))
            ->add(__('connecthistory.menu.overview'));

        $this->bootHistory();

        if (!$this->configured) {
            return;
        }

        $this->metrics = $this->decorateMetrics($this->cached(
            'overview-metrics',
            fn () => $this->history->overviewMetrics($this->filter),
            (int) config('connecthistory.cache.metrics', 60)
        ));

        $this->activeTab = (string) request()->input('tab-' . self::TABS_SLUG, self::TAB_ONLINE);
        $this->loadActiveTab();
    }

    public function layout(): array
    {
        if (!$this->configured) {
            return [$this->notConfiguredLayout()];
        }

        return [
            LayoutFactory::metrics([
                __('connecthistory.metrics.players') => 'metrics.players',
                __('connecthistory.metrics.sessions') => 'metrics.sessions',
                __('connecthistory.metrics.avg_session') => 'metrics.avg_session_human',
                __('connecthistory.metrics.peak_online') => 'metrics.peak_online',
                __('connecthistory.metrics.newcomers') => 'metrics.newcomers',
                __('connecthistory.metrics.retention') => 'metrics.retention_human',
            ])->setIcons([
                __('connecthistory.metrics.players') => 'users-three',
                __('connecthistory.metrics.sessions') => 'clock-counter-clockwise',
                __('connecthistory.metrics.avg_session') => 'hourglass-medium',
                __('connecthistory.metrics.peak_online') => 'trend-up',
                __('connecthistory.metrics.newcomers') => 'user-plus',
                __('connecthistory.metrics.retention') => 'percent',
            ]),

            $this->baseFilters()->checkbox('skip_short', __('connecthistory.filters.skip_short')),

            LayoutFactory::tabs([
                $this->tab(self::TAB_ONLINE, 'ph.regular.pulse', $this->chart('area', 340)),
                $this->tab(self::TAB_JOINS, 'ph.regular.sign-in', $this->chart('bar', 320)),
                $this->tab(self::TAB_HEATMAP, 'ph.regular.grid-four', $this->chart('heatmap', 380)),
                $this->tab(self::TAB_NEWCOMERS, 'ph.regular.user-plus', $this->chart('bar', 320)),
                $this->tab(self::TAB_MAPS, 'ph.regular.map-trifold', $this->chart('bar', 360)),
                $this->tab(self::TAB_REASONS, 'ph.regular.sign-out', $this->chart('donut', 340)),
                $this->tab(self::TAB_GEO, 'ph.regular.globe-hemisphere-east', $this->geoLayout()),
                $this->tab(self::TAB_CRASHES, 'ph.regular.warning-octagon', $this->crashesLayout()),
            ])->slug(self::TABS_SLUG)->lazyload(),
        ];
    }

    // --- данные ------------------------------------------------------------

    /**
     * Каждая ветка — один запрос и одна подпись. Ничего не пересчитывается
     * между базой и графиком.
     */
    protected function loadActiveTab(): void
    {
        switch ($this->activeTab) {
            case self::TAB_JOINS:
                $rows = $this->cached('joins', fn () => $this->history->joins($this->filter));
                $this->labels = $this->buckets($rows, $this->filter->useHourlyBuckets() ? 'd.m H:i' : 'd.m');
                $this->series = [
                    ['name' => __('connecthistory.charts.joins'), 'data' => $this->column($rows, 'sessions')],
                    ['name' => __('connecthistory.charts.unique'), 'data' => $this->column($rows, 'players')],
                ];
                break;

            case self::TAB_HEATMAP:
                $this->buildHeatmap();
                break;

            case self::TAB_NEWCOMERS:
                $rows = $this->cached('newcomers', fn () => $this->history->newcomers($this->filter));
                $this->labels = $this->buckets($rows);
                $this->series = [
                    ['name' => __('connecthistory.charts.newcomers'), 'data' => $this->column($rows, 'newcomers')],
                    ['name' => __('connecthistory.charts.returned'), 'data' => $this->column($rows, 'returned')],
                ];
                break;

            case self::TAB_MAPS:
                $rows = $this->cached('maps', fn () => $this->history->topMaps($this->filter));
                $this->labels = array_map(static fn (array $r) => (string) $r['bucket'], $rows);
                $this->series = [
                    ['name' => __('connecthistory.charts.hours'), 'data' => $this->column($rows, 'hours')],
                ];
                break;

            case self::TAB_REASONS:
                $rows = $this->cached('reasons', fn () => $this->history->disconnectReasons($this->filter));
                $this->labels = array_map(static fn (array $r) => (string) $r['bucket'], $rows);
                // Donut ждёт плоский массив чисел, а не пары name/data
                $this->series = $this->column($rows, 'sessions');
                break;

            case self::TAB_GEO:
                $this->geoRows = $this->cached('geo', fn () => $this->mergeGeo());
                $this->labels = array_map(static fn (array $r) => (string) $r['bucket'], $this->geoRows);
                $this->series = [
                    ['name' => __('connecthistory.charts.players'), 'data' => $this->column($this->geoRows, 'players')],
                ];
                break;

            case self::TAB_CRASHES:
                $this->crashes = $this->cached('crashes', fn () => $this->history->crashes($this->filter));
                break;

            case self::TAB_ONLINE:
            default:
                $rows = $this->cached('online', fn () => $this->history->onlineTimeline($this->filter));
                $this->labels = $this->buckets($rows, $this->filter->useHourlyBuckets() ? 'd.m H:i' : 'd.m');
                $this->series = [
                    ['name' => __('connecthistory.charts.avg_online'), 'data' => $this->column($rows, 'avg_players')],
                    ['name' => __('connecthistory.charts.peak_online'), 'data' => $this->column($rows, 'peak_players')],
                ];
                break;
        }
    }

    /**
     * Тепловая карта «час x день недели»: ряд на день недели, 24 точки в ряду.
     * WEEKDAY() в MySQL отдаёт 0 = понедельник.
     */
    protected function buildHeatmap(): void
    {
        $rows = $this->cached('heatmap', fn () => $this->history->activityHeatmap($this->filter));

        $grid = [];

        foreach ($rows as $row) {
            $grid[(int) $row['weekday']][(int) $row['hour']] = (float) $row['avg_players'];
        }

        $this->labels = array_map(
            static fn (int $hour) => str_pad((string) $hour, 2, '0', STR_PAD_LEFT),
            range(0, 23)
        );

        $days = [
            __('connecthistory.weekdays.mon'),
            __('connecthistory.weekdays.tue'),
            __('connecthistory.weekdays.wed'),
            __('connecthistory.weekdays.thu'),
            __('connecthistory.weekdays.fri'),
            __('connecthistory.weekdays.sat'),
            __('connecthistory.weekdays.sun'),
        ];

        $series = [];

        // Снизу вверх: в ApexCharts первый ряд рисуется внизу, а неделя читается сверху
        for ($day = 6; $day >= 0; $day--) {
            $series[] = [
                'name' => $days[$day],
                'data' => array_map(
                    static fn (int $hour) => $grid[$day][$hour] ?? 0,
                    range(0, 23)
                ),
            ];
        }

        $this->series = $series;
    }

    /** Аудитория и качество связи — один разрез, два вопроса. */
    protected function mergeGeo(): array
    {
        $geo = $this->history->geography($this->filter);
        $ping = [];

        foreach ($this->history->pingByCountry($this->filter) as $row) {
            $ping[(string) $row['bucket']] = $row;
        }

        foreach ($geo as $index => $row) {
            $code = (string) $row['bucket'];
            $geo[$index]['avg_ping'] = $ping[$code]['avg_ping'] ?? null;
        }

        return $geo;
    }

    // --- слои ---------------------------------------------------------------

    protected function tab(string $slug, string $icon, $layout): Tab
    {
        return Tab::make(__('connecthistory.tabs.' . $slug))
            ->slug($slug)
            ->icon($icon)
            ->layouts([$layout]);
    }

    protected function chart(string $type, int $height)
    {
        return LayoutFactory::chart('series', __('connecthistory.tabs.' . $this->activeTab))
            ->type($type)
            ->labels($this->labels)
            ->height($height)
            ->description(__('connecthistory.charts.' . $this->activeTab . '_description'));
    }

    protected function geoLayout()
    {
        return LayoutFactory::blank([
            $this->chart('bar', 320),

            LayoutFactory::table('geoRows', [
                TD::make('bucket', __('connecthistory.geo.country'))
                    ->width('220px')
                    ->render(static fn (array $row) => e((string) ($row['country_name'] ?: $row['bucket']))),
                TD::make('players', __('connecthistory.geo.players'))->alignCenter()->width('120px'),
                TD::make('sessions', __('connecthistory.geo.sessions'))->alignCenter()->width('120px'),
                TD::make('hours', __('connecthistory.geo.hours'))->alignCenter()->width('120px'),
                TD::make('avg_ping', __('connecthistory.geo.avg_ping'))
                    ->alignCenter()
                    ->width('130px')
                    ->popover(__('connecthistory.geo.avg_ping_help'))
                    ->render(static fn (array $row) => ($row['avg_ping'] ?? null) === null
                        ? '—'
                        : (string) (int) $row['avg_ping']),
            ])
                ->compact()
                ->perPage(25)
                ->exportable(true, 'connect-history-geo')
                ->empty('ph.regular.globe', __('connecthistory.geo.empty')),
        ]);
    }

    protected function crashesLayout()
    {
        return LayoutFactory::table('crashes', [
            TD::make('bucket', __('connecthistory.crashes.day'))
                ->width('160px')
                ->render(fn (array $row) => $this->toPanelTime(($row['bucket'] ?? '') . ' 00:00:00', 'd.m.Y')),
            TD::make('connect_map', __('connecthistory.crashes.map'))->width('200px'),
            TD::make('interrupted_sessions', __('connecthistory.crashes.sessions'))
                ->alignCenter()
                ->width('180px'),
            TD::make('players', __('connecthistory.crashes.players'))->alignCenter()->width('140px'),
        ])
            ->title(__('connecthistory.crashes.title'))
            ->description(__('connecthistory.crashes.description'))
            ->perPage(20)
            ->exportable(true, 'connect-history-crashes')
            ->empty(
                'ph.regular.check-circle',
                __('connecthistory.crashes.empty'),
                __('connecthistory.crashes.empty_sub')
            );
    }
}
