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

    /** Больше зеркал в списке не бывает, а разбор текста из формы должен быть ограничен. */
    public const MAX_MIRRORS = 50;

    /**
     * Единственный способ прочитать additional.
     *
     * Тип результата НЕ зависит от того, заполнено поле или нет: на null, пустой
     * строке, битом JSON, JSON-массиве и уже готовом массиве возвращается одна
     * и та же структура. Прошлый модуль читал ->sid у значения, которое при
     * пустом поле было массивом, и падал с "Attempt to read property on array".
     *
     * @return array{server_id: int, prefix: string, mirrors: string}
     */
    public static function readAdditional(mixed $additional): array
    {
        $decoded = self::decode($additional);

        // sid — ключ старого модуля. Читаем и его: дешевле, чем объяснять админу,
        // почему перенесённое подключение выглядит пустым.
        $serverId = $decoded['server_id'] ?? $decoded['sid'] ?? 0;

        $mirrors = $decoded['mirrors'] ?? '';

        return [
            'server_id' => is_scalar($serverId) ? (int) $serverId : 0,
            'prefix' => self::sanitizePrefix($decoded['prefix'] ?? null),
            'mirrors' => is_scalar($mirrors) ? (string) $mirrors : '',
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
     * @return array{server_id: int, prefix: string, mirrors: string}
     */
    public static function prepare(array $data): array
    {
        $serverId = $data['server_id'] ?? 0;

        // Список зеркал сохраняется уже нормализованным: мусорные строки
        // отбрасываются на входе, а не при каждом чтении.
        $mirrors = [];
        foreach (self::parseMirrors($data['mirrors'] ?? null) as $ip => $label) {
            $mirrors[] = $ip === $label ? $ip : $ip . ' ' . $label;
        }

        return [
            'server_id' => is_scalar($serverId) ? (int) $serverId : 0,
            'prefix' => self::sanitizePrefix($data['prefix'] ?? null),
            'mirrors' => implode("\n", $mirrors),
        ];
    }

    /**
     * Разбор списка зеркал из текстового поля: строка на зеркало,
     * «адрес» либо «адрес название» (разделитель — пробел, = или |).
     *
     * Зачем вообще: трафик через зеркало проксируется, поэтому игровой сервер
     * видит адрес зеркала, а не игрока. Без этого списка такие сессии выглядят
     * как десятки разных людей с одного адреса — и страна в них тоже не игрока,
     * а зеркала.
     *
     * Совпадение только точное: зеркало — постоянный хост с известным адресом,
     * подсети тут ничего не добавляют, а ошибиться дают легко.
     *
     * @return array<string, string> адрес -> название (по умолчанию сам адрес)
     */
    public static function parseMirrors(mixed $raw): array
    {
        if (is_array($raw)) {
            $raw = implode("\n", array_filter($raw, 'is_scalar'));
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $mirrors = [];

        foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s*[=|]\s*|\s+/', $line, 2) ?: [];
            $ip = $parts[0] ?? '';

            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $label = trim($parts[1] ?? '');
            $mirrors[$ip] = $label !== '' ? mb_substr($label, 0, 64) : $ip;

            if (count($mirrors) >= self::MAX_MIRRORS) {
                break;
            }
        }

        return $mirrors;
    }

    /**
     * Правила валидации настроек подключения.
     *
     * ВАЖНО: этот список решает не только «что проверить», но и «что сохранить».
     * Панель кладёт в additional ТОЛЬКО ключи, перечисленные здесь
     * (Server/Screens/Concerns/HandlesDbActions.php), поэтому поле, забытое
     * в правилах, молча выбрасывается при сохранении — форма его показывает,
     * пользователь заполняет, а в базу оно не попадает.
     *
     * Ключи обязаны совпадать с тем, что возвращает prepare(); это проверяет тест.
     *
     * server_id обязателен: без него нельзя отличить один игровой сервер от другого
     * внутри общей базы плагина.
     *
     * @return array<string, array<int, string>>
     */
    public static function validationRules(): array
    {
        return [
            'server_id' => ['required', 'numeric'],
            'prefix' => ['nullable', 'string', 'max-str-len:16'],
            'mirrors' => ['nullable', 'string', 'max-str-len:2000'],
        ];
    }

    /**
     * Оставляет из привязок только те серверы, где у игрока есть сессии.
     *
     * Выбрать сервер, на котором игрока никогда не было, — значит получить
     * пустую карточку и не понять причину: данных нет не потому, что их нет,
     * а потому что выбран не тот сервер.
     *
     * Сопоставление идёт по server_id ПЛАГИНА: ключ привязки — это номер сервера
     * в панели, а в сессиях лежит номер из конфига плагина, и совпадать они
     * не обязаны.
     *
     * @param array<int, array<string, mixed>> $bindings        привязки панели; форма
     *                                                           не гарантируется, метод публичный
     * @param array<int, int>                  $playerServerIds server_id из сессий игрока
     * @return array<int, string> id сервера панели -> название
     */
    public static function optionsForPlayer(array $bindings, array $playerServerIds): array
    {
        $known = array_flip(array_map('intval', $playerServerIds));
        $options = [];

        foreach ($bindings as $fluteServerId => $binding) {
            $pluginServerId = (int) ($binding['server_id'] ?? 0);

            if (!isset($known[$pluginServerId])) {
                continue;
            }

            $name = $binding['server']->name ?? null;

            $options[(int) $fluteServerId] = is_scalar($name) && trim((string) $name) !== ''
                ? (string) $name
                : '#' . $fluteServerId;
        }

        return $options;
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
