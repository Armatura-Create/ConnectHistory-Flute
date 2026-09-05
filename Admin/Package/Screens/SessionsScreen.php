<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Admin\Package\Screens;

use Flute\Admin\Platform\Fields\TD;
use Flute\Admin\Platform\Layouts\Filters;
use Flute\Admin\Platform\Layouts\LayoutFactory;
use Flute\Admin\Platform\Screen;
use Flute\Modules\ConnectHistory\Admin\Package\ConnectHistoryPackage;
use Flute\Modules\ConnectHistory\Admin\Package\Screens\Concerns\ResolvesHistory;
use Flute\Modules\ConnectHistory\Services\SessionFilter;
use Illuminate\Support\Collection;

/**
 * Сессии: построчно или сводом по выбранному измерению.
 *
 * Один экран отвечает на разные вопросы за счёт переключателя группировки —
 * это и есть «группировка по всему», о которой обычно просят: не отдельная
 * страница на каждый разрез, а один набор фильтров и смена измерения.
 *
 * Построчный режим отдаёт SelectQuery: пагинация, сортировка и LIMIT остаются
 * в SQL. Сводный режим отдаёт готовый агрегат с жёстким потолком по числу групп —
 * в PHP приезжают группы, а не сессии.
 */
class SessionsScreen extends Screen
{
    use ResolvesHistory;

    public ?string $name = null;

    public ?string $description = null;

    public ?string $permission = ConnectHistoryPackage::PERMISSION_VIEW;

    /** SelectQuery в построчном режиме, массив агрегатов — в сводном. */
    public mixed $sessions = null;

    /** @var array<string, mixed> */
    public array $metrics = [];

    protected bool $withPii = false;

    protected int $groupCap = 500;

    public function mount(): void
    {
        $this->name = __('connecthistory.sessions.title');
        $this->description = __('connecthistory.sessions.description');

        breadcrumb()
            ->add(__('def.admin_panel'), (string) url('/admin'))
            ->add(__('connecthistory.menu.overview'), (string) url('/admin/connect-history'))
            ->add(__('connecthistory.menu.sessions'));

        $this->bootHistory();

        if (!$this->configured) {
            return;
        }

        $this->withPii = $this->canSeePii();
        $this->groupCap = (int) config('connecthistory.max_groups', 500);

        $this->sessions = $this->filter->groupBy === SessionFilter::GROUP_NONE
            ? $this->history->sessionsQuery($this->filter, $this->withPii)
            : $this->history->groupedSessions($this->filter, $this->groupCap);

        $this->metrics = $this->decorateMetrics($this->cached(
            'sessions-metrics',
            fn () => $this->history->overviewMetrics($this->filter),
            (int) config('connecthistory.cache.metrics', 60)
        ));
    }

    public function layout(): array
    {
        if (!$this->configured) {
            return [$this->notConfiguredLayout()];
        }

        return [
            LayoutFactory::metrics([
                __('connecthistory.metrics.sessions') => 'metrics.sessions',
                __('connecthistory.metrics.players') => 'metrics.players',
                __('connecthistory.metrics.avg_session') => 'metrics.avg_session_human',
                __('connecthistory.metrics.crashed') => 'metrics.crashed',
            ])->setIcons([
                __('connecthistory.metrics.sessions') => 'clock-counter-clockwise',
                __('connecthistory.metrics.players') => 'users-three',
                __('connecthistory.metrics.avg_session') => 'hourglass-medium',
                __('connecthistory.metrics.crashed') => 'warning-octagon',
            ]),

            $this->filters(),

            $this->filter->groupBy === SessionFilter::GROUP_NONE
                ? $this->rowsTable()
                : $this->groupedTable(),
        ];
    }

    protected function filters(): Filters
    {
        $filters = $this->baseFilters();

        $filters->buttonGroup('state', __('connecthistory.filters.state'), [
            SessionFilter::STATE_ALL => __('connecthistory.states.all'),
            SessionFilter::STATE_ONLINE => __('connecthistory.states.online'),
            SessionFilter::STATE_CLOSED => __('connecthistory.states.closed'),
            SessionFilter::STATE_CRASHED => __('connecthistory.states.crashed'),
        ], SessionFilter::STATE_ALL);

        $filters->buttonGroup('group', __('connecthistory.filters.group'), [
            SessionFilter::GROUP_NONE => __('connecthistory.groups.none'),
            SessionFilter::GROUP_PLAYER => __('connecthistory.groups.player'),
            SessionFilter::GROUP_MAP => __('connecthistory.groups.map'),
            SessionFilter::GROUP_COUNTRY => __('connecthistory.groups.country'),
            SessionFilter::GROUP_DAY => __('connecthistory.groups.day'),
            SessionFilter::GROUP_REASON => __('connecthistory.groups.reason'),
        ], SessionFilter::GROUP_NONE);

        $filters->input(
            'search',
            __('connecthistory.filters.search'),
            'text',
            null,
            __('connecthistory.filters.search_placeholder')
        );

        // Списки значений берутся из самих данных: показывать карту, которой
        // на сервере никогда не было, — это предлагать заведомо пустой результат.
        $options = $this->cached('filter-options', fn () => [
            'maps' => $this->history->mapOptions($this->filter),
            'countries' => $this->history->countryOptions($this->filter),
            'reasons' => $this->history->reasonOptions($this->filter),
        ]);

        // Первым пунктом каждого списка идёт его собственное название: шаблон
        // Filters в ядре не выводит label для select, и без этого в ряд встают
        // три одинаковых «Выберите опцию». Значение '' = «не фильтровать».
        if ($options['maps'] !== []) {
            $filters->select(
                'map',
                __('connecthistory.filters.map'),
                ['' => __('connecthistory.filters.all_maps')] + $options['maps'],
                $this->filter->map,
                allowEmpty: false,
            );
        }

        if ($options['countries'] !== []) {
            $filters->select(
                'country',
                __('connecthistory.filters.country'),
                ['' => __('connecthistory.filters.all_countries')] + $options['countries'],
                $this->filter->country,
                allowEmpty: false,
            );
        }

        if ($options['reasons'] !== []) {
            $filters->select(
                'reason',
                __('connecthistory.filters.reason'),
                ['' => __('connecthistory.filters.all_reasons')] + $options['reasons'],
                $this->filter->reason,
                allowEmpty: false,
            );
        }

        return $filters
            ->input('min_minutes', __('connecthistory.filters.min_minutes'), 'number')
            ->input('max_minutes', __('connecthistory.filters.max_minutes'), 'number')
            ->checkbox('only_new', __('connecthistory.filters.only_new'))
            ->checkbox('skip_short', __('connecthistory.filters.skip_short'));
    }

    /** Построчный режим. */
    protected function rowsTable()
    {
        $columns = [
            TD::make('nickname', __('connecthistory.sessions.column_player'))
                ->sort()
                ->width('260px')
                ->render(static fn (array $row) => view('connecthistory::admin.cells.player', [
                    'row' => $row,
                ])->render()),

            TD::make('started_at', __('connecthistory.sessions.column_started'))
                ->sort()
                ->defaultSort(true, 'desc')
                ->width('160px')
                ->render(fn (array $row) => $this->toPanelTime($row['started_at'] ?? null)),

            TD::make('duration_seconds', __('connecthistory.sessions.column_duration'))
                ->sort()
                ->alignCenter()
                ->width('120px')
                ->render(fn (array $row) => $this->humanDuration($row['duration_seconds'] ?? null)),

            TD::make('end_kind', __('connecthistory.sessions.column_state'))
                ->sort()
                ->width('150px')
                ->render(fn (array $row) => view('connecthistory::admin.cells.state', [
                    'state' => $this->sessionState($row['ended_at'] ?? null, $row['end_kind'] ?? 0),
                    'reason' => $row['disconnect_reason_name'] ?? null,
                ])->render()),

            TD::make('connect_map', __('connecthistory.sessions.column_map'))
                ->sort()
                ->width('150px'),

            TD::make('country_iso', __('connecthistory.sessions.column_country'))
                ->sort()
                ->alignCenter()
                ->width('120px')
                ->render(static fn (array $row) => (string) ($row['country_name'] ?? $row['country_iso'] ?? '—')),

            TD::make('kills', __('connecthistory.sessions.column_kd'))
                ->alignCenter()
                ->width('110px')
                ->disableSearch()
                ->render(static function (array $row): string {
                    $kills = (int) ($row['kills'] ?? 0);
                    $deaths = (int) ($row['deaths'] ?? 0);

                    return $kills === 0 && $deaths === 0
                        ? '—'
                        : $kills . ' / ' . $deaths;
                }),

            TD::make('ping_avg', __('connecthistory.sessions.column_ping'))
                ->sort()
                ->alignCenter()
                ->width('100px')
                ->defaultHidden()
                ->render(static fn (array $row) => ($row['ping_avg'] ?? null) === null
                    ? '—'
                    : (string) (int) $row['ping_avg']),

            TD::make('players_online', __('connecthistory.sessions.column_online_then'))
                ->sort()
                ->alignCenter()
                ->width('120px')
                ->defaultHidden(),

            TD::make('server_id', __('connecthistory.sessions.column_server'))
                ->sort()
                ->alignCenter()
                ->width('100px')
                ->defaultHidden(),
        ];

        // Персональные колонки добавляются, только если они реально есть в SELECT.
        // Без права pii их нет в запросе — прятать в вёрстке нечего.
        if ($this->withPii) {
            $columns[] = TD::make('player_ip', __('connecthistory.sessions.column_ip'))
                ->width('150px')
                ->defaultHidden()
                ->popover(__('connecthistory.sessions.column_ip_help'));

            $columns[] = TD::make('city', __('connecthistory.sessions.column_city'))
                ->width('150px')
                ->defaultHidden();
        }

        return LayoutFactory::table('sessions', $columns)
            ->title(__('connecthistory.sessions.table_title'))
            ->searchable(['nickname', 'steamid64'])
            ->exportable(true, 'connect-history-sessions')
            ->perPage((int) config('connecthistory.per_page', 25))
            ->dataCallback(fn ($rows) => $this->attachIdentities($rows))
            ->empty(
                'ph.regular.clock-counter-clockwise',
                __('connecthistory.sessions.empty'),
                __('connecthistory.sessions.empty_sub')
            );
    }

    /** Сводный режим: одна строка на группу. */
    protected function groupedTable()
    {
        $isPlayer = $this->filter->groupBy === SessionFilter::GROUP_PLAYER;

        $columns = [
            TD::make('bucket', $this->groupLabel())
                ->sort()
                ->width('280px')
                ->render(fn (array $row) => $isPlayer
                    ? view('connecthistory::admin.cells.player', ['row' => $row])->render()
                    : e((string) ($row['bucket'] ?? '—'))),

            TD::make('sessions', __('connecthistory.grouped.sessions'))
                ->sort()
                ->defaultSort(true, 'desc')
                ->alignCenter()
                ->width('120px'),

            TD::make('players', __('connecthistory.grouped.players'))
                ->sort()
                ->alignCenter()
                ->width('120px'),

            TD::make('total_seconds', __('connecthistory.grouped.total_time'))
                ->sort()
                ->alignCenter()
                ->width('140px')
                ->render(fn (array $row) => $this->humanDuration($row['total_seconds'] ?? 0)),

            TD::make('avg_seconds', __('connecthistory.grouped.avg_time'))
                ->sort()
                ->alignCenter()
                ->width('140px')
                ->render(fn (array $row) => $this->humanDuration($row['avg_seconds'] ?? 0)),

            TD::make('kills', __('connecthistory.grouped.kd'))
                ->alignCenter()
                ->width('120px')
                ->render(static function (array $row): string {
                    $kills = (int) ($row['kills'] ?? 0);
                    $deaths = (int) ($row['deaths'] ?? 0);

                    return $kills === 0 && $deaths === 0 ? '—' : $kills . ' / ' . $deaths;
                }),

            TD::make('last_seen', __('connecthistory.grouped.last_seen'))
                ->sort()
                ->width('160px')
                ->render(fn (array $row) => $this->toPanelTime($row['last_seen'] ?? null)),
        ];

        $table = LayoutFactory::table('sessions', $columns)
            ->title(__('connecthistory.grouped.title', ['dimension' => $this->groupLabel()]))
            ->exportable(true, 'connect-history-grouped')
            ->perPage((int) config('connecthistory.per_page', 25))
            ->empty(
                'ph.regular.chart-bar',
                __('connecthistory.sessions.empty'),
                __('connecthistory.sessions.empty_sub')
            );

        if ($isPlayer) {
            $table->dataCallback(fn ($rows) => $this->attachIdentities($rows));
        }

        // Потолок групп — не «магическое число», а видимая пользователю граница:
        // молча обрезанный список хуже, чем список с подписью.
        if (count((array) $this->sessions) >= $this->groupCap) {
            $table->description(__('connecthistory.grouped.capped', ['limit' => $this->groupCap]));
        }

        return $table;
    }

    /**
     * Личности игроков для ОДНОЙ страницы, одной пачкой.
     *
     * Вызывается после пагинации, поэтому число запрашиваемых профилей
     * ограничено размером страницы, а не размером выборки.
     */
    protected function attachIdentities($rows)
    {
        $collection = $rows instanceof Collection ? $rows : collect($rows);

        if ($collection->isEmpty()) {
            return $collection;
        }

        $identities = $this->identities($collection->all());

        return $collection->map(static function ($row) use ($identities) {
            $row = (array) $row;
            $row['identity'] = $identities[(string) ($row['steamid64'] ?? '')] ?? null;

            return $row;
        });
    }

    protected function groupLabel(): string
    {
        return match ($this->filter->groupBy) {
            SessionFilter::GROUP_PLAYER => __('connecthistory.groups.player'),
            SessionFilter::GROUP_MAP => __('connecthistory.groups.map'),
            SessionFilter::GROUP_COUNTRY => __('connecthistory.groups.country'),
            SessionFilter::GROUP_DAY => __('connecthistory.groups.day'),
            SessionFilter::GROUP_REASON => __('connecthistory.groups.reason'),
            default => __('connecthistory.groups.none'),
        };
    }
}
