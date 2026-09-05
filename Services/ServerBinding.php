<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Services;

use Throwable;

/**
 * Связка «сервер панели -> сервер плагина» и её разбор.
 *
 * Класс намеренно не зависит от Flute: разбор additional и проверка префикса —
 * это места, где предыдущий модуль ломался (docs/AUTOPSY.md №6), и они обязаны
 * проверяться тестами без поднятия CMS. Драйвер мода — тонкий адаптер поверх.
 */
final class ServerBinding
{
    public const DEFAULT_PREFIX = 'ch_';

    /**
     * Единственный способ прочитать additional.
     *
     * Тип результата НЕ зависит от того, заполнено поле или нет: на null, пустой
     * строке, битом JSON, JSON-массиве и уже готовом массиве возвращается одна
     * и та же структура. Прошлый модуль читал ->sid у значения, которое при
     * пустом поле было массивом, и падал с "Attempt to read property on array".
     *
     * @return array{server_id: int, prefix: string}
     */
    public static function readAdditional(mixed $additional): array
    {
        $decoded = self::decode($additional);

        // sid — ключ старого модуля. Читаем и его: дешевле, чем объяснять админу,
        // почему перенесённое подключение выглядит пустым.
        $serverId = $decoded['server_id'] ?? $decoded['sid'] ?? 0;

        return [
            'server_id' => is_scalar($serverId) ? (int) $serverId : 0,
            'prefix' => self::sanitizePrefix($decoded['prefix'] ?? null),
        ];
    }

    /**
     * Префикс попадает в SQL как часть идентификатора таблицы — параметризовать
     * его нельзя. Поэтому белый список символов, как в самом плагине
     * (DatabaseService.SanitizePrefix): латиница, цифры, подчёркивание, до 16 знаков.
     *
     * Якорь \z, а не $: в PCRE $ совпадает и ПЕРЕД завершающим переводом строки,
     * поэтому "ch_\n" прошёл бы проверку и уехал в текст SQL. Поймано тестом.
     */
    public static function sanitizePrefix(mixed $prefix): string
    {
        if (!is_string($prefix) || $prefix === '') {
            return self::DEFAULT_PREFIX;
        }

        return preg_match('/^[A-Za-z0-9_]{1,16}\z/', $prefix) === 1 ? $prefix : self::DEFAULT_PREFIX;
    }

    /**
     * Нормализация формы настроек подключения перед сохранением.
     *
     * @param array<string, mixed> $data
     * @return array{server_id: int, prefix: string}
     */
    public static function prepare(array $data): array
    {
        $serverId = $data['server_id'] ?? 0;

        return [
            'server_id' => is_scalar($serverId) ? (int) $serverId : 0,
            'prefix' => self::sanitizePrefix($data['prefix'] ?? null),
        ];
    }

    /**
     * server_id обязателен: без него нельзя отличить один игровой сервер от другого
     * внутри общей базы плагина.
     *
     * @return array<string, string>
     */
    public static function validationRules(): array
    {
        return [
            'server_id' => 'required|numeric',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(mixed $additional): array
    {
        if (is_array($additional)) {
            return $additional;
        }

        if (is_object($additional)) {
            return (array) $additional;
        }

        if (!is_string($additional) || trim($additional) === '') {
            return [];
        }

        try {
            $decoded = json_decode($additional, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
