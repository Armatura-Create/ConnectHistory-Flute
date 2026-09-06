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

/**
 * Серверы плагина: справочник ch_servers плюс живое состояние.
 *
 * Здесь же диагностика адреса. Плагин определяет адрес через ConVar ip, который
 * при биндинге на все интерфейсы равен 0.0.0.0 — процесс сервера не знает своего
 * публичного адреса и знать не может. Экран показывает это прямо, вместо того
 * чтобы молча выводить 0.0.0.0 как настоящий адрес.
 */
class ServersScreen extends Screen
{
    use ResolvesHistory;

    public ?string $name = null;

    public ?string $description = null;

    public ?string $permission = ConnectHistoryPackage::PERMISSION_VIEW;

    /** @var array<int, array<string, mixed>> */
    public array $servers = [];

    /** @var array<string, mixed> */
    public array $metrics = [];

    public function mount(): void
    {
        $this->name = __('connecthistory.servers.title');
        $this->description = __('connecthistory.servers.description');

        breadcrumb()
            ->add(__('def.admin_panel'), (string) url('/admin'))
            ->add(__('connecthistory.menu.overview'), (string) url('/admin/connect-history'))
            ->add(__('connecthistory.menu.servers'));

        $this->bootHistory();

        if (!$this->configured) {
            return;
        }

        $rows = $this->cached(
            'servers',
            fn () => $this->history->servers(),
            (int) config('connecthistory.cache.online', 15)
        );

        $this->servers = array_map(fn (array $row) => $this->decorate($row), $rows);

        $this->metrics = [
            'servers' => count($this->servers),
            'online' => array_sum(array_column($this->servers, 'online_now')),
            'crashes' => array_sum(array_column($this->servers, 'crashes_30d')),
            'broken_address' => count(array_filter(
                $this->servers,
                static fn (array $row) => $row['address_broken'] === true
            )),
        ];
    }

    public function commandBar(): array
    {
        return [
            Button::make(__('connecthistory.actions.refresh'))
                ->icon('ph.regular.arrows-clockwise')
                ->method('refresh')
                ->type(Color::OUTLINE_DEFAULT),
        ];
    }

    public function layout(): array
    {
        if (!$this->configured) {
            return [$this->notConfiguredLayout()];
        }

        $layout = [];

        if ($filter = $this->serverFilter()) {
            $layout[] = $filter;
        }

        return array_merge($layout, [
            $this->metricsRow([
                ['label' => __('connecthistory.servers.metric_servers'), 'icon' => 'hard-drives',
                 'value' => $this->metrics['servers'] ?? 0],
                ['label' => __('connecthistory.servers.metric_online'), 'icon' => 'users-three',
                 'value' => $this->metrics['online'] ?? 0,
                 'help' => __('connecthistory.help.online_now')],
                ['label' => __('connecthistory.servers.metric_crashes'), 'icon' => 'warning-octagon',
                 'value' => $this->metrics['crashes'] ?? 0,
                 'help' => __('connecthistory.help.crashed')],
                ['label' => __('connecthistory.servers.metric_broken_address'), 'icon' => 'plugs',
                 'value' => $this->metrics['broken_address'] ?? 0,
                 'help' => __('connecthistory.help.broken_address')],
            ]),

            LayoutFactory::table('servers', [
                TD::make('id', __('connecthistory.servers.column_id'))
                    ->width('70px')
                    ->alignCenter(),

                TD::make('hostname', __('connecthistory.servers.column_hostname'))
                    ->render(static fn (array $row) => view('connecthistory::admin.cells.server', [
                        'row' => $row,
                    ])->render()),

                TD::make('online_now', __('connecthistory.servers.column_online'))
                    ->popover(__('connecthistory.help.online_now'))
                    ->alignCenter()
                    ->width('110px')
                    ->render(static fn (array $row) => (string) (int) $row['online_now']),

                TD::make('last_snapshot', __('connecthistory.servers.column_heartbeat'))
                    ->popover(__('connecthistory.help.heartbeat'))
                    ->width('190px')
                    ->render(fn (array $row) => view('connecthistory::admin.cells.heartbeat', [
                        'row' => $row,
                    ])->render()),

                TD::make('crashes_30d', __('connecthistory.servers.column_crashes'))
                    ->popover(__('connecthistory.help.crashed'))
                    ->alignCenter()
                    ->width('130px')
                    ->render(static fn (array $row) => (int) $row['crashes_30d'] > 0
                        ? '<span class="badge danger">' . (int) $row['crashes_30d'] . '</span>'
                        : '<span class="text-muted">0</span>'),

                TD::make('last_seen', __('connecthistory.servers.column_last_seen'))
                    ->width('170px')
                    ->render(fn (array $row) => $this->toPanelTime($row['last_seen'] ?? null)),
            ])
                ->title(__('connecthistory.servers.table_title'))
                ->empty(
                    'ph.regular.hard-drives',
                    __('connecthistory.servers.empty'),
                    __('connecthistory.servers.empty_sub')
                ),
        ]);
    }

    public function refresh(): void
    {
        $this->mount();
    }

    /**
     * Разметка «сломанного» адреса.
     *
     * 0.0.0.0 — это адрес привязки сокета, а не адрес сервера. Пустая строка
     * означает, что плагин уже отбраковал такое значение и ждёт Server.PublicAddress
     * в конфиге. И то и другое одинаково бесполезно как адрес подключения.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decorate(array $row): array
    {
        $address = trim((string) ($row['address'] ?? ''));

        $row['address'] = $address;
        $row['address_broken'] = $address === ''
            || str_starts_with($address, '0.0.0.0')
            || str_starts_with($address, '[::]')
            || str_starts_with($address, ':');

        $gap = (int) config('connecthistory.snapshot_gap_seconds', 900);
        $lastSnapshot = $row['last_snapshot'] ?? null;

        $row['silent'] = $lastSnapshot === null
            || (time() - strtotime((string) $lastSnapshot . ' UTC')) > $gap;

        $row['last_snapshot_human'] = $this->toPanelTime($lastSnapshot);

        return $row;
    }
}
