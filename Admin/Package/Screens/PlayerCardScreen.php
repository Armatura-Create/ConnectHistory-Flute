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
        $this->sessions = $this->history->playerSessionsQuery($this->steamid64, $this->withPii);

        $activity = $this->history->playerActivity($this->steamid64);
        $this->activityLabels = $this->buckets($activity);
        $this->activity = [[
            'name' => __('connecthistory.player.minutes_played'),
            'data' => $this->column($activity, 'minutes'),
        ]];

        // Персональный блок — только за отдельным правом
        if ($this->withPii) {
            $this->alts = $this->history->possibleAlts($this->steamid64);
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

        $layout = [
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

            LayoutFactory::columns([
                LayoutFactory::table('nicknames', [
                    TD::make('nickname', __('connecthistory.player.column_nickname')),
                    TD::make('times_seen', __('connecthistory.player.column_times'))
                        ->alignCenter()
                        ->width('110px'),
                    TD::make('last_seen', __('connecthistory.player.column_last_used'))
                        ->width('160px')
                        ->render(fn (array $row) => $this->toPanelTime($row['last_seen'] ?? null)),
                ])
                    ->title(__('connecthistory.player.nicknames_title'))
                    ->compact()
                    ->perPage(10)
                    ->empty('ph.regular.textbox', __('connecthistory.player.nicknames_empty')),

                $this->altsLayout(),
            ]),

            LayoutFactory::table('sessions', $this->sessionColumns())
                ->title(__('connecthistory.player.sessions_title'))
                ->exportable(true, 'player-' . $this->steamid64)
                ->perPage(20)
                ->empty('ph.regular.clock-counter-clockwise', __('connecthistory.sessions.empty')),
        ];

        return $layout;
    }

    /**
     * Блок мультиаккаунтов существует только при праве pii — и запрос за ним
     * тоже не выполняется, см. mount().
     */
    protected function altsLayout()
    {
        if (!$this->withPii) {
            return LayoutFactory::view('connecthistory::admin.pii-hidden');
        }

        return LayoutFactory::table('alts', [
            TD::make('nickname', __('connecthistory.player.column_alt'))
                ->render(fn (array $row) => view('connecthistory::admin.cells.player', [
                    'row' => $row,
                    'link' => (string) url('/admin/connect-history/player/' . rawurlencode((string) $row['steamid64'])),
                ])->render()),
            TD::make('sessions', __('connecthistory.grouped.sessions'))
                ->alignCenter()
                ->width('110px'),
            TD::make('last_seen', __('connecthistory.grouped.last_seen'))
                ->width('160px')
                ->render(fn (array $row) => $this->toPanelTime($row['last_seen'] ?? null)),
        ])
            ->title(__('connecthistory.player.alts_title'))
            ->description(__('connecthistory.player.alts_description'))
            ->compact()
            ->perPage(10)
            ->empty('ph.regular.users', __('connecthistory.player.alts_empty'));
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
