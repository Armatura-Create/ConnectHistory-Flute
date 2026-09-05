<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Providers;

use DI\Container;
use Flute\Admin\Packages\Server\Factories\ModDriverFactory;
use Flute\Core\Support\ModuleServiceProvider;
use Flute\Modules\ConnectHistory\Admin\Drivers\ConnectHistoryModDriver;
use Flute\Modules\ConnectHistory\Admin\Package\ConnectHistoryPackage;
use Throwable;

/**
 * Точка входа модуля.
 *
 * boot() только РЕГИСТРИРУЕТ. Ни одного запроса к базе здесь нет и быть не должно:
 * провайдер выполняется на каждом запросе к панели, а данные нужны лишь на своих
 * маршрутах. Чтение живёт в Screen::mount() — после проверки прав.
 * См. docs/AUTOPSY.md №8 и №3.
 */
class ConnectHistoryProvider extends ModuleServiceProvider
{
    public array $extensions = [];

    public function boot(Container $container): void
    {
        $this->bootstrapModule();

        $this->loadViews('Resources/views', 'connecthistory');
        $this->loadScss('Resources/assets/scss/connecthistory.scss');

        $this->registerModDriver($container);

        $this->loadPackage(new ConnectHistoryPackage());
    }

    public function register(Container $container): void
    {
    }

    /**
     * Драйвер мода добавляет ConnectHistory в список типов подключения
     * в админском разделе «Серверы».
     *
     * Регистрация идемпотентна: ModDriverFactory::register() бросает исключение
     * при повторной регистрации ключа, а провайдер может быть загружен дважды
     * (например, после включения модуля без перезагрузки процесса).
     */
    protected function registerModDriver(Container $container): void
    {
        try {
            $factory = $container->get(ModDriverFactory::class);

            if (!$factory->hasDriver(ConnectHistoryModDriver::MOD_KEY)) {
                $factory->register(ConnectHistoryModDriver::MOD_KEY, ConnectHistoryModDriver::class);
            }
        } catch (Throwable $e) {
            logs()->warning('[ConnectHistory] Не удалось зарегистрировать драйвер мода: ' . $e->getMessage());
        }
    }
}
