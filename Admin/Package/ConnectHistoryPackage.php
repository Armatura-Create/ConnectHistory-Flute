<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Admin\Package;

use Flute\Admin\Support\AbstractAdminPackage;

/**
 * Админский пакет: маршруты, права и пункты меню.
 *
 * В getMenuItems() сознательно нет badge со счётчиком онлайна: он выполнялся бы
 * на каждой странице панели, включая те, где раздел не открывают
 * (docs/AUTOPSY.md №8).
 */
class ConnectHistoryPackage extends AbstractAdminPackage
{
    /** Просмотр раздела. */
    public const PERMISSION_VIEW = 'admin.connecthistory';

    /** Персональные данные: IP, подсеть, хеш IP, город. */
    public const PERMISSION_PII = 'admin.connecthistory.pii';

    public function initialize(): void
    {
        parent::initialize();

        $this->loadRoutesFromFile('routes.php');
    }

    public function getPermissions(): array
    {
        return ['admin', self::PERMISSION_VIEW, self::PERMISSION_PII];
    }

    public function getMenuItems(): array
    {
        $permission = [self::PERMISSION_VIEW];

        return [
            [
                'key' => 'connecthistory-overview',
                'title' => __('connecthistory.menu.overview'),
                'icon' => 'ph.regular.chart-line-up',
                'url' => url('/admin/connect-history'),
                'permission' => $permission,
                'permission_mode' => 'any',
            ],
            [
                'key' => 'connecthistory-sessions',
                'title' => __('connecthistory.menu.sessions'),
                'icon' => 'ph.regular.clock-counter-clockwise',
                'url' => url('/admin/connect-history/sessions'),
                'permission' => $permission,
                'permission_mode' => 'any',
            ],
            [
                'key' => 'connecthistory-players',
                'title' => __('connecthistory.menu.players'),
                'icon' => 'ph.regular.users-three',
                'url' => url('/admin/connect-history/players'),
                'permission' => $permission,
                'permission_mode' => 'any',
            ],
            [
                'key' => 'connecthistory-servers',
                'title' => __('connecthistory.menu.servers'),
                'icon' => 'ph.regular.hard-drives',
                'url' => url('/admin/connect-history/servers'),
                'permission' => $permission,
                'permission_mode' => 'any',
            ],
        ];
    }

    public function getPriority(): int
    {
        return 52;
    }
}
