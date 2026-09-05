<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Admin\Package\Screens;

use Flute\Admin\Platform\Actions\Button;
use Flute\Admin\Platform\Fields\TD;
use Flute\Admin\Platform\Layouts\LayoutFactory;
use Flute\Admin\Platform\Screen;
use Flute\Admin\Platform\Support\Color;
use Flute\Modules\ConnectHistory\Admin\Package\ConnectHistoryPackage;
use Flute\Modules\ConnectHistory\Admin\Package\Screens\Concerns\ResolvesHistory;
use Flute\Modules\ConnectHistory\Services\PlayerIdentityService;

/**
 * Карточка игрока: агрегаты, активность, история ников, последние сессии.
 *
 * steamid64 приходит из маршрута строкой и строкой же остаётся: 64-битное число
 * не помещается в PHP int на 32-битной сборке и теряет точность при приведении.
 */
class PlayerCardScreen extends Screen
{
    use ResolvesHistory;

    public ?string $name = null;

    public ?string $description = null;

    public ?string $permission = ConnectHistoryPackage::PERMISSION_VIEW;

    public string $steamid64 = '';

    /** @var array<string, mixed>|null */
    public ?array $player = null;

    /** @var array<string, mixed>|null */
    public ?array $identity = null;

    /** @var array<string, mixed> */
    public array $metrics = [];

    /** @var array<int, array<string, mixed>> */
    public array $nicknames = [];

    /** @var array<int, array<string, mixed>> */
    public array $alts = [];

    /** @var array<string, mixed> */
    public array $summary = [];

    /** @var array<int, array<string, mixed>> */
    public array $servers = [];

    /** @var array<int, array<string, mixed>> */
    public array $maps = [];

    /** @var array<int, array<string, mixed>> */
    public array $reasons = [];

    /** @var array<int, array<string, mixed>> */
    public array $ipHistory = [];

    /** @var array<int, array<string, mixed>> */
    public array $activity = [];

    /** @var array<int, string> */
    public array $activityLabels = [];

    public mixed $sessions = null;

    protected bool $withPii = false;

    public function mount(): void
    {
        // Идентификатор — только цифры. Всё остальное не может быть SteamID64,
        // и запрос с таким значением делать незачем.
        $raw = (string) request()->attributes->get('steamid64', request()->input('steamid64', ''));
        $this->steamid64 = preg_match('/^\d{1,20}\z/', $raw) === 1 ? $raw : '';

        $this->bootHistory();
        $this->withPii = $this->canSeePii();

        if (!$this->configured || $this->steamid64 === '') {
            $this->name = __('connecthistory.player.not_found');

            return;
        }

        $this->player = $this->history->player($this->steamid64);

        $fallbackName = $this->player['last_nickname'] ?? null;
        $this->identity = app(PlayerIdentityService::class)->resolve(
            $this->steamid64,
            is_scalar($fallbackName) ? (string) $fallbackName : null
        );

        $this->name = $this->identity['name'];
        $this->description = __('connecthistory.player.description', ['steamid' => $this->steamid64]);

        breadcrumb()
            ->add(__('def.admin_panel'), (string) url('/admin'))
            ->add(__('connecthistory.menu.players'), (string) url('/admin/connect-history/players'))
            ->add($this->identity['name']);

        if ($this->player === null) {
            return;
        }

        $this->metrics = [
            'playtime' => $this->humanDuration($this->player['total_seconds'] ?? 0),
            'sessions' => (int) ($this->player['sessions_count'] ?? 0),
            'first_seen' => $this->toPanelTime($this->player['first_seen'] ?? null, 'd.m.Y'),
            'last_seen' => $this->toPanelTime($this->player['last_seen'] ?? null),
        ];

        $this->nicknames = $this->history->playerNicknames($this->steamid64);
        $this->summary = $this->history->playerSummary($this->steamid64);
        $this->servers = $this->history->playerServers($this->steamid64);
        $this->maps = $this->history->playerMaps($this->steamid64);
        $this->reasons = $this->history->playerReasons($this->steamid64);
        $this->sessions = $this->history->playerSessionsQuery($this->steamid64, $this->withPii);

        $activity = $this->history->playerActivity($this->steamid64);
        $this->activityLabels = $this->buckets($activity);
        $this->activity = [[
            'name' => __('connecthistory.player.minutes_played'),
            'data' => $this->column($activity, 'minutes'),
        ]];

        // Персональные блоки — только за отдельным правом: без него эти запросы
        // не выполняются вовсе, а не прячутся в вёрстке.
        if ($this->withPii) {
            $this->alts = $this->history->possibleAlts($this->steamid64);
            $this->ipHistory = $this->history->playerIpHistory($this->steamid64);
        }
    }

    public function commandBar(): array
    {
        if ($this->steamid64 === '') {
            return [];
        }

        $bar = [
            Button::make(__('connecthistory.player.open_steam'))
                ->icon('ph.regular.arrow-square-out')
                ->href(PlayerIdentityService::steamCommunityUrl($this->steamid64))
                ->type(Color::OUTLINE_DEFAULT),
            Button::make(__('connecthistory.player.all_sessions'))
                ->icon('ph.regular.clock-counter-clockwise')
                ->href((string) url('/admin/connect-history/sessions')->addParams(['search' => $this->steamid64])),
        ];

        if (($this->identity['user_id'] ?? null) !== null) {
            array_unshift(
                $bar,
                Button::make(__('connecthistory.player.open_profile'))
                    ->icon('ph.regular.user')
                    ->href((string) url('/admin/users/' . (int) $this->identity['user_id']))
            );
        }

        return $bar;
    }

    public function layout(): array
    {
        if (!$this->configured) {
            return [$this->notConfiguredLayout()];
        }

        if ($this->player === null) {
            return [LayoutFactory::view('connecthistory::admin.player-missing', [
                'steamid64' => $this->steamid64,
            ])];
        }

        return [
            LayoutFactory::metrics([
                __('connecthistory.player.metric_playtime') => 'metrics.playtime',
                __('connecthistory.player.metric_sessions') => 'metrics.sessions',
                __('connecthistory.player.metric_first_seen') => 'metrics.first_seen',
                __('connecthistory.player.metric_last_seen') => 'metrics.last_seen',
            ])->setIcons([
                __('connecthistory.player.metric_playtime') => 'hourglass-medium',
                __('connecthistory.player.metric_sessions') => 'clock-counter-clockwise',
                __('connecthistory.player.metric_first_seen') => 'flag',
                __('connecthistory.player.metric_last_seen') => 'clock',
            ]),

            LayoutFactory::chart('activity', __('connecthistory.player.activity_title'))
                ->type('bar')
                ->labels($this->activityLabels)
                ->height(220)
                ->description(__('connecthistory.player.activity_description')),

            // Справочные блоки — обычные вьюхи, а не таблицы платформы.
            //
            // Причина: Layouts\Table берёт номер страницы и сортировку из ОБЩИХ
            // GET-параметров page и sort. Несколько таблиц на одном экране делят
            // их между собой: листаешь одну — листаются все, сортируешь одну —
            // сортируются все. Этим блокам пагинация не нужна, они и так
            // ограничены LIMIT в SQL.
            LayoutFactory::view('connecthistory::admin.player.profile', [
                'player' => $this->player,
                'identity' => $this->identity,
                'summary' => $this->summary,
                'steamid64' => $this->steamid64,
            ]),

            LayoutFactory::columns([
                LayoutFactory::view('connecthistory::admin.player.nicknames', [
                    'rows' => $this->nicknames,
                ]),
                LayoutFactory::view('connecthistory::admin.player.servers', [
                    'rows' => $this->servers,
                ]),
            ]),

            LayoutFactory::columns([
                LayoutFactory::view('connecthistory::admin.player.maps', [
                    'rows' => $this->maps,
                ]),
                LayoutFactory::view('connecthistory::admin.player.reasons', [
                    'rows' => $this->reasons,
                ]),
            ]),

            // Сетку строит платформа (Columns), а не вьюха: своя разметка row/col
            // внутри чужой сетки — лишний уровень, который легко расходится
            // с отступами панели.
            $this->withPii
                ? LayoutFactory::columns([
                    LayoutFactory::view('connecthistory::admin.player.ip', ['rows' => $this->ipHistory]),
                    LayoutFactory::view('connecthistory::admin.player.alts', ['rows' => $this->alts]),
                ])
                : LayoutFactory::view('connecthistory::admin.pii-hidden'),

            // Единственная таблица на экране — значит page и sort принадлежат ей
            LayoutFactory::table('sessions', $this->sessionColumns())
                ->title(__('connecthistory.player.sessions_title'))
                ->exportable(true, 'player-' . $this->steamid64)
                ->perPage(20)
                ->empty('ph.regular.clock-counter-clockwise', __('connecthistory.sessions.empty')),
        ];
    }

    /** @return array<int, TD> */
    protected function sessionColumns(): array
    {
        $columns = [
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

            TD::make('connect_map', __('connecthistory.sessions.column_map'))->sort()->width('150px'),

            TD::make('kills', __('connecthistory.sessions.column_kd'))
                ->alignCenter()
                ->width('110px')
                ->render(static function (array $row): string {
                    $kills = (int) ($row['kills'] ?? 0);
                    $deaths = (int) ($row['deaths'] ?? 0);

                    return $kills === 0 && $deaths === 0 ? '—' : $kills . ' / ' . $deaths;
                }),

            TD::make('ping_avg', __('connecthistory.sessions.column_ping'))
                ->sort()
                ->alignCenter()
                ->width('100px')
                ->render(static fn (array $row) => ($row['ping_avg'] ?? null) === null
                    ? '—'
                    : (string) (int) $row['ping_avg']),
        ];

        if ($this->withPii) {
            $columns[] = TD::make('player_ip', __('connecthistory.sessions.column_ip'))
                ->width('150px')
                ->defaultHidden();
        }

        return $columns;
    }
}
