<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Admin\Package\Screens;

use Flute\Admin\Platform\Fields\TD;
use Flute\Admin\Platform\Layouts\Filters;
use Flute\Admin\Platform\Layouts\LayoutFactory;
use Flute\Admin\Platform\Screen;
use Flute\Modules\ConnectHistory\Admin\Package\ConnectHistoryPackage;
use Flute\Modules\ConnectHistory\Admin\Package\Screens\Concerns\ResolvesHistory;
use Illuminate\Support\Collection;

/**
 * Игроки: агрегаты из ch_players.
 *
 * Таблица сессий отвечает на вопрос «что происходило», эта — на вопрос «кто у нас есть».
 * Данные уже свёрнуты плагином при закрытии сессии, поэтому здесь обычный SelectQuery
 * без GROUP BY: пагинация и сортировка целиком в SQL.
 */
class PlayersScreen extends Screen
{
    use ResolvesHistory;

    public ?string $name = null;

    public ?string $description = null;

    public ?string $permission = ConnectHistoryPackage::PERMISSION_VIEW;

    public mixed $players = null;

    /** @var array<string, mixed> */
    public array $metrics = [];

    public function mount(): void
    {
        $this->name = __('connecthistory.players.title');
        $this->description = __('connecthistory.players.description');

        breadcrumb()
            ->add(__('def.admin_panel'), (string) url('/admin'))
            ->add(__('connecthistory.menu.overview'), (string) url('/admin/connect-history'))
            ->add(__('connecthistory.menu.players'));

        $this->bootHistory();

        if (!$this->configured) {
            return;
        }

        $this->players = $this->history->playersQuery($this->filter);

        $this->metrics = $this->decorateMetrics($this->cached(
            'players-metrics',
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
                __('connecthistory.metrics.players') => 'metrics.players',
                __('connecthistory.metrics.newcomers') => 'metrics.newcomers',
                __('connecthistory.metrics.returned') => 'metrics.returned',
                __('connecthistory.metrics.retention') => 'metrics.retention_human',
            ])->setIcons([
                __('connecthistory.metrics.players') => 'users-three',
                __('connecthistory.metrics.newcomers') => 'user-plus',
                __('connecthistory.metrics.returned') => 'arrow-u-up-left',
                __('connecthistory.metrics.retention') => 'percent',
            ]),

            $this->filters(),

            LayoutFactory::table('players', [
                TD::make('last_nickname', __('connecthistory.players.column_player'))
                    ->sort()
                    ->width('280px')
                    ->render(static fn (array $row) => view('connecthistory::admin.cells.player', [
                        'row' => $row + ['nickname' => $row['last_nickname'] ?? null],
                        'link' => url('/admin/connect-history/player/' . rawurlencode((string) $row['steamid64'])),
                    ])->render()),

                TD::make('total_seconds', __('connecthistory.players.column_playtime'))
                    ->sort()
                    ->defaultSort(true, 'desc')
                    ->alignCenter()
                    ->width('150px')
                    ->render(fn (array $row) => $this->humanDuration($row['total_seconds'] ?? 0)),

                TD::make('sessions_count', __('connecthistory.players.column_sessions'))
                    ->sort()
                    ->alignCenter()
                    ->width('120px'),

                TD::make('first_seen', __('connecthistory.players.column_first_seen'))
                    ->sort()
                    ->width('160px')
                    ->render(fn (array $row) => $this->toPanelTime($row['first_seen'] ?? null)),

                TD::make('last_seen', __('connecthistory.players.column_last_seen'))
                    ->sort()
                    ->width('160px')
                    ->render(fn (array $row) => $this->toPanelTime($row['last_seen'] ?? null)),

                TD::make('last_country', __('connecthistory.players.column_country'))
                    ->sort()
                    ->alignCenter()
                    ->width('110px')
                    ->render(static fn (array $row) => (string) ($row['last_country'] ?? '—')),

                TD::make('last_server_id', __('connecthistory.players.column_server'))
                    ->sort()
                    ->alignCenter()
                    ->width('100px')
                    ->defaultHidden(),
            ])
                ->title(__('connecthistory.players.table_title'))
                ->searchable(['last_nickname', 'steamid64'])
                ->exportable(true, 'connect-history-players')
                ->perPage((int) config('connecthistory.per_page', 25))
                ->dataCallback(fn ($rows) => $this->attachIdentities($rows))
                ->empty(
                    'ph.regular.users-three',
                    __('connecthistory.players.empty'),
                    __('connecthistory.players.empty_sub')
                ),
        ];
    }

    protected function filters(): Filters
    {
        return $this->baseFilters()
            ->input(
                'search',
                __('connecthistory.filters.search'),
                'text',
                null,
                __('connecthistory.filters.search_placeholder')
            )
            ->input('min_minutes', __('connecthistory.filters.min_playtime'), 'number')
            ->checkbox('only_new', __('connecthistory.filters.only_new_cohort'));
    }

    protected function attachIdentities($rows)
    {
        $collection = $rows instanceof Collection ? $rows : collect($rows);

        if ($collection->isEmpty()) {
            return $collection;
        }

        $identities = $this->identities($collection->all(), 'steamid64', 'last_nickname');

        return $collection->map(static function ($row) use ($identities) {
            $row = (array) $row;
            $row['identity'] = $identities[(string) ($row['steamid64'] ?? '')] ?? null;

            return $row;
        });
    }
}
