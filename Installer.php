<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory;

use Flute\Core\ModulesManager\ModuleInformation;
use Flute\Core\Support\AbstractModuleInstaller;

/**
 * Установка модуля.
 *
 * Модуль ничего не создаёт в базе панели: он только читает чужую базу,
 * которую наполняет игровой плагин. Поэтому install/uninstall пустые —
 * вся настройка делается в разделе «Серверы» привязкой подключения
 * с модом ConnectHistory.
 *
 * Класс ModuleInformation живёт в Flute\Core\ModulesManager, а не в
 * Flute\Core\Modules — на этом молча ломался предыдущий модуль,
 * см. docs/AUTOPSY.md №4.
 */
class Installer extends AbstractModuleInstaller
{
    public function install(ModuleInformation &$module): bool
    {
        return true;
    }

    public function uninstall(ModuleInformation &$module): bool
    {
        return true;
    }
}
