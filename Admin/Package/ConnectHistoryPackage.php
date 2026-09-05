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

        $this->loadRoutesFromFile('Admin/Package/routes.php');

        // Стили админ-экранов подключаются ИМЕННО здесь, в группу ассетов «admin».
        //
        // ModuleServiceProvider::loadScss() кладёт файл в группу «main» — это тема
        // сайта, и в панели он не загружается вовсе. Пока карточка игрока
        // полагалась на него, она рисовалась совсем без стилей.
        $this->registerScss('Resources/assets/scss/admin.scss');
    }

    /**
     * База пакета — корень модуля, а не каталог Admin/Package.
     *
     * По умолчанию AbstractAdminPackage берёт каталог собственного класса,
     * и пути к ресурсам превращались бы в «../../Resources/...».
     */
    public function getBasePath(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getPermissions(): array
    {
        return ['admin', self::PERMISSION_VIEW, self::PERMISSION_PII];
    }

    /**
     * Пункты сознательно БЕЗ ключа 'key'.
     *
     * AdminPackageFactory разводит их так: пункт с ключом ждёт, что этот ключ
     * перечислен в config('admin-menu') панели, и не найдя его — сваливает пункт
     * в безымянную секцию «остальное» плоским списком. Пункт БЕЗ ключа от модуля
     * попадает в секцию своего модуля, которая в сайдбаре раскрывается отдельным
     * уровнем. Второе — то, что нужно: четыре плоских пункта в общем списке
     * ничего не группируют.
     *
     * Заголовок раздела панель берёт из имени модуля. Чтобы вместо этого собрать
     * свою секцию (например «Аналитика»), пункты нужно вернуть с ключами и
     * перечислить их в config/admin-menu.php — см. README.
     */
    public function getMenuItems(): array
    {
        $permission = [self::PERMISSION_VIEW];

        return [
            [
                'title' => __('connecthistory.menu.overview'),
                'icon' => 'ph.regular.chart-line-up',
                'url' => url('/admin/connect-history'),
                'permission' => $permission,
                'permission_mode' => 'any',
            ],
            [
                'title' => __('connecthistory.menu.sessions'),
                'icon' => 'ph.regular.clock-counter-clockwise',
                'url' => url('/admin/connect-history/sessions'),
                'permission' => $permission,
                'permission_mode' => 'any',
            ],
            [
                'title' => __('connecthistory.menu.players'),
                'icon' => 'ph.regular.users-three',
                'url' => url('/admin/connect-history/players'),
                'permission' => $permission,
                'permission_mode' => 'any',
            ],
            [
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
