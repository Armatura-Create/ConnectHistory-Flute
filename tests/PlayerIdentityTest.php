<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Tests;

use Flute\Modules\ConnectHistory\Services\PlayerIdentityService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Отказ №2 из docs/AUTOPSY.md: предыдущий модуль падал с
 * "Attempt to read property avatar on null", как только в выборку попадал игрок
 * с приватным или удалённым Steam-профилем.
 *
 * Проверяется свойство, которое делает это невозможным: для КАЖДОГО запрошенного
 * SteamID возвращается заполненная запись, чем бы ни ответили Flute и Steam.
 */
final class PlayerIdentityTest extends TestCase
{
    private const A = '76561198000000001';
    private const B = '76561198000000002';
    private const C = '76561198000000003';

    public function testFluteUserWinsOverSteam(): void
    {
        $result = PlayerIdentityService::merge(
            [self::A],
            [self::A => ['name' => 'Локальный', 'avatar' => '/avatar.png', 'user_id' => 42, 'uri' => 'vasya']],
            [self::A => ['name' => 'Стимовый', 'avatar' => 'https://steam/a.jpg']],
            [self::A => 'ИзБазы'],
        );

        self::assertSame('Локальный', $result[self::A]['name']);
        self::assertSame('/profile/vasya', $result[self::A]['url']);
        self::assertSame(42, $result[self::A]['user_id']);
        self::assertSame(PlayerIdentityService::SOURCE_FLUTE, $result[self::A]['source']);
    }

    public function testSteamUsedWhenPlayerIsNotOnTheSite(): void
    {
        $result = PlayerIdentityService::merge(
            [self::A],
            [],
            [self::A => ['name' => 'Стимовый', 'avatar' => 'https://steam/a.jpg']],
            [],
        );

        self::assertSame('Стимовый', $result[self::A]['name']);
        self::assertSame(PlayerIdentityService::SOURCE_STEAM, $result[self::A]['source']);
        self::assertStringContainsString(self::A, $result[self::A]['url']);
    }

    /**
     * Ровно тот случай, на котором падал старый модуль: Steam не вернул игрока.
     */
    public function testPrivateProfileFallsBackToStoredNickname(): void
    {
        $result = PlayerIdentityService::merge(
            [self::A],
            [],
            [],
            [self::A => 'НикИзСессии'],
        );

        self::assertSame('НикИзСессии', $result[self::A]['name']);
        self::assertNull($result[self::A]['avatar']);
        self::assertSame(PlayerIdentityService::SOURCE_FALLBACK, $result[self::A]['source']);
    }

    public function testCompletelyUnknownPlayerStillGetsRecord(): void
    {
        $result = PlayerIdentityService::merge([self::A], [], [], []);

        self::assertArrayHasKey(self::A, $result);
        self::assertSame(self::A, $result[self::A]['name'], 'без ника подписью служит сам SteamID');
        self::assertNull($result[self::A]['user_id']);
    }

    /**
     * Steam Web API отвечает пачкой и может вернуть не всех, кого спросили.
     */
    public function testPartialSteamResponseLeavesNoGaps(): void
    {
        $result = PlayerIdentityService::merge(
            [self::A, self::B, self::C],
            [self::C => ['name' => 'Сайт', 'avatar' => null, 'user_id' => 7, 'uri' => null]],
            [self::A => ['name' => 'Стим', 'avatar' => 'https://steam/a.jpg']],
            [self::B => 'ТолькоНик'],
        );

        self::assertCount(3, $result);
        self::assertSame(PlayerIdentityService::SOURCE_STEAM, $result[self::A]['source']);
        self::assertSame(PlayerIdentityService::SOURCE_FALLBACK, $result[self::B]['source']);
        self::assertSame(PlayerIdentityService::SOURCE_FLUTE, $result[self::C]['source']);
        self::assertSame('/profile/7', $result[self::C]['url'], 'без uri ссылка идёт по id');
    }

    #[DataProvider('brokenPayloads')]
    public function testBrokenPayloadsNeverThrow(mixed $fluteUser, mixed $steamInfo): void
    {
        $result = PlayerIdentityService::merge(
            [self::A],
            [self::A => $fluteUser],
            [self::A => $steamInfo],
            [],
        );

        self::assertArrayHasKey(self::A, $result);
        self::assertNotSame('', $result[self::A]['name']);
        self::assertIsString($result[self::A]['url']);
    }

    public static function brokenPayloads(): array
    {
        return [
            'оба null' => [null, null],
            'пустые массивы' => [[], []],
            'пустые имена' => [['name' => ''], ['name' => '  ']],
            'вместо массива строка' => ['мусор', 'мусор'],
            'вместо массива число' => [0, 0],
            'имя не строка' => [['name' => ['вложенный']], ['name' => ['вложенный']]],
        ];
    }

    public function testEmptyInputProducesEmptyResult(): void
    {
        self::assertSame([], PlayerIdentityService::merge([], [], [], []));
    }

    public function testSteamIdIsNeverCastToInteger(): void
    {
        $result = PlayerIdentityService::merge([self::A], [], [], []);

        self::assertArrayHasKey(self::A, $result, 'ключ остаётся строкой SteamID64');
        self::assertSame(
            'https://steamcommunity.com/profiles/' . self::A,
            $result[self::A]['url']
        );
    }

    public function testFallbackHelperIsSelfSufficient(): void
    {
        $withName = PlayerIdentityService::fallback(self::A, 'Ник');
        $withoutName = PlayerIdentityService::fallback(self::A, null);

        self::assertSame('Ник', $withName['name']);
        self::assertSame(self::A, $withoutName['name']);
        self::assertSame(PlayerIdentityService::SOURCE_FALLBACK, $withoutName['source']);
    }
}
