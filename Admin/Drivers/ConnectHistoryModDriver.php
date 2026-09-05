<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Admin\Drivers;

use Flute\Admin\Packages\Server\Contracts\ModDriverInterface;
use Flute\Modules\ConnectHistory\Services\ServerBinding;

/**
 * Тип подключения «ConnectHistory» в админском разделе «Серверы».
 *
 * Одна база плагина обслуживает несколько игровых серверов, они различаются
 * колонкой server_id. Поэтому связка «сервер панели -> сервер плагина» хранится
 * в additional конкретного подключения.
 *
 * Класс — адаптер к интерфейсу панели. Вся логика в ServerBinding, чтобы её
 * можно было проверить тестами без Flute.
 */
class ConnectHistoryModDriver implements ModDriverInterface
{
    public const MOD_KEY = 'ConnectHistory';

    public function getName(): string
    {
        return self::MOD_KEY;
    }

    public function getSettingsView(): string
    {
        return 'connecthistory::admin.mod-settings';
    }

    public function hasSettings(): bool
    {
        return true;
    }

    public function getValidationRules(): array
    {
        return ServerBinding::validationRules();
    }

    public function prepareData(array $data): array
    {
        return ServerBinding::prepare($data);
    }
}
