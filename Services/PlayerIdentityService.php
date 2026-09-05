<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Services;

use Cycle\Database\Injection\Parameter;
use Flute\Core\Database\Entities\UserSocialNetwork;
use Throwable;

/**
 * Кто этот SteamID64 — человеком понятными словами.
 *
 * Плагин хранит только steamid64 и ник на момент сессии. Аватара у него нет,
 * и это нормально: аватар не является частью истории подключений.
 *
 * Три уровня, и каждый — обычная ветка, а не исключение:
 *   1. пользователь Flute с привязанным Steam — аватар и имя уже локально;
 *   2. Steam Web API одной пачкой на страницу (SteamService сам батчит и кеширует);
 *   3. ник из ch_sessions + ссылка на steamcommunity по steamid64.
 *
 * Третий уровень доступен ВСЕГДА и не требует сети. Поэтому отсутствующий,
 * приватный или удалённый профиль не может уронить страницу — ровно на этом
 * падал предыдущий модуль (docs/AUTOPSY.md №2).
 */
final class PlayerIdentityService
{
    public const SOURCE_FLUTE = 'flute';
    public const SOURCE_STEAM = 'steam';
    public const SOURCE_FALLBACK = 'fallback';

    /** Ключи сети Steam во Flute: обычная привязка и вариант через https. */
    private const STEAM_KEYS = ['Steam', 'HttpsSteam'];

    /**
     * @param array<int, mixed>       $steamIds      SteamID64 (приводятся к строке)
     * @param array<string, string>   $fallbackNames steamid64 => ник из ch_sessions
     * @return array<string, array{name: string, avatar: ?string, url: string, user_id: ?int, source: string}>
     */
    public function resolveMany(array $steamIds, array $fallbackNames = []): array
    {
        $steamIds = array_values(array_unique(array_filter(array_map(
            static fn ($id) => is_scalar($id) ? trim((string) $id) : '',
            $steamIds
        ), static fn (string $id) => $id !== '')));

        if ($steamIds === []) {
            return [];
        }

        $fluteUsers = $this->lookupFluteUsers($steamIds);

        // В Steam идём только за теми, кого нет на сайте
        $missing = array_values(array_diff($steamIds, array_keys($fluteUsers)));
        $steamInfo = $missing === [] ? [] : $this->lookupSteam($missing);

        return self::merge($steamIds, $fluteUsers, $steamInfo, $fallbackNames);
    }

    /** Удобная обёртка для одного игрока. */
    public function resolve(string $steamId, ?string $fallbackName = null): array
    {
        $names = $fallbackName !== null ? [$steamId => $fallbackName] : [];

        return $this->resolveMany([$steamId], $names)[$steamId] ?? self::fallback($steamId, $fallbackName);
    }

    /**
     * Сборка результата из трёх источников.
     *
     * Метод чистый и не знает про Flute — именно он проверяется тестами
     * на пустом, частичном и битом ответе Steam.
     *
     * @param array<int, mixed>    $steamIds
     * @param array<string, mixed> $fluteUsers    ожидается {name, avatar, user_id, uri}, но не гарантируется
     * @param array<string, mixed> $steamInfo     ожидается {name, avatar}, но не гарантируется
     * @param array<string, mixed> $fallbackNames
     * @return array<string, array{name: string, avatar: ?string, url: string, user_id: ?int, source: string}>
     */
    public static function merge(
        array $steamIds,
        array $fluteUsers,
        array $steamInfo,
        array $fallbackNames = [],
    ): array {
        $result = [];

        foreach ($steamIds as $steamId) {
            $steamId = (string) $steamId;
            $fallbackName = self::stringOrNull($fallbackNames[$steamId] ?? null);

            $user = $fluteUsers[$steamId] ?? null;

            if (is_array($user) && self::stringOrNull($user['name'] ?? null) !== null) {
                $result[$steamId] = [
                    'name' => (string) $user['name'],
                    'avatar' => self::stringOrNull($user['avatar'] ?? null),
                    'url' => self::profileUrl($user),
                    'user_id' => isset($user['user_id']) ? (int) $user['user_id'] : null,
                    'source' => self::SOURCE_FLUTE,
                ];

                continue;
            }

            // SteamService отдаёт массив; на приватном профиле записи может не быть вовсе
            $info = $steamInfo[$steamId] ?? null;
            $steamName = is_array($info) ? self::stringOrNull($info['name'] ?? null) : null;

            if ($steamName !== null) {
                $result[$steamId] = [
                    'name' => $steamName,
                    'avatar' => is_array($info) ? self::stringOrNull($info['avatar'] ?? null) : null,
                    'url' => self::steamCommunityUrl($steamId),
                    'user_id' => null,
                    'source' => self::SOURCE_STEAM,
                ];

                continue;
            }

            $result[$steamId] = self::fallback($steamId, $fallbackName);
        }

        return $result;
    }

    /**
     * Последний уровень: работает без сети и без базы панели.
     *
     * @return array{name: string, avatar: ?string, url: string, user_id: ?int, source: string}
     */
    public static function fallback(string $steamId, ?string $name = null): array
    {
        return [
            'name' => self::stringOrNull($name) ?? $steamId,
            'avatar' => null,
            'url' => self::steamCommunityUrl($steamId),
            'user_id' => null,
            'source' => self::SOURCE_FALLBACK,
        ];
    }

    public static function steamCommunityUrl(string $steamId): string
    {
        return 'https://steamcommunity.com/profiles/' . rawurlencode($steamId);
    }

    // --- источники ---------------------------------------------------------

    /**
     * Пользователи сайта с привязанным Steam.
     *
     * Один запрос на всю страницу. Ключ сети проверяется в PHP: строк здесь
     * не больше, чем игроков на странице.
     *
     * @param array<int, string> $steamIds
     * @return array<string, array{name: string, avatar: ?string, user_id: int, uri: ?string}>
     */
    private function lookupFluteUsers(array $steamIds): array
    {
        try {
            $links = UserSocialNetwork::query()
                ->load('user')
                ->load('socialNetwork')
                ->where('value', 'IN', new Parameter($steamIds))
                ->fetchAll();
        } catch (Throwable $e) {
            logs()->warning('[ConnectHistory] Поиск пользователей по Steam не удался: ' . $e->getMessage());

            return [];
        }

        $found = [];

        foreach ($links as $link) {
            if (!in_array($link->socialNetwork->key ?? '', self::STEAM_KEYS, true)) {
                continue;
            }

            $user = $link->user ?? null;

            if ($user === null) {
                continue;
            }

            $found[(string) $link->value] = [
                'name' => (string) $user->name,
                'avatar' => $user->avatar,
                'user_id' => (int) $user->id,
                'uri' => $user->uri,
            ];
        }

        return $found;
    }

    /**
     * @param array<int, string> $steamIds
     * @return array<string, mixed>
     */
    private function lookupSteam(array $steamIds): array
    {
        try {
            // SteamService сам режет на пачки по 100, кеширует и молча пропускает
            // невалидные ID. При пустом API-ключе вернёт [] — это не ошибка.
            return steam()->getUsersInfo($steamIds);
        } catch (Throwable $e) {
            logs()->warning('[ConnectHistory] Steam Web API недоступен: ' . $e->getMessage());

            return [];
        }
    }

    // --- мелочи ------------------------------------------------------------

    /** @param array<string, mixed> $user */
    private static function profileUrl(array $user): string
    {
        $identifier = self::stringOrNull($user['uri'] ?? null) ?? (string) ($user['user_id'] ?? '');

        return $identifier === '' ? '#' : '/profile/' . $identifier;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
