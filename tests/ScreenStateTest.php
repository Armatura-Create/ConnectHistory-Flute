<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Идентификатор игрока обязан переживать перерисовку экрана.
 *
 * Что ломалось: параметр маршрута приходит только на первый рендер, а любой
 * фильтр перерисовывает компонент через yoyo — запросом уже без пути. Ядро
 * восстанавливает публичные свойства только из тех, что напечатало скрытым
 * полем, а печатает оно ровно подписанные (Screen::render -> screenSignedValues).
 * Автоопределение берёт свойства с именем `id` или `*Id`, под которые steamid64
 * не подходит, — значит подпись обязана быть объявлена явно. Без неё карточка
 * на любой фильтр отвечала «игрок не найден», а селектор серверов показывал все
 * серверы, потому что сужение живёт ниже по mount().
 *
 * Проверка текстовая: класс наследует Screen и без CMS не грузится, а поймать
 * пропажу одной строки важнее, чем иметь красивый тест.
 */
final class ScreenStateTest extends TestCase
{
    public function testPlayerCardSignsItsIdentifier(): void
    {
        $source = self::source();

        self::assertMatchesRegularExpression(
            '/function signedProperties\(\)\s*:\s*array\s*\{\s*return \[[^\]]*\'steamid64\'/s',
            $source,
            'PlayerCardScreen должен объявлять steamid64 подписанным свойством'
        );
    }

    /**
     * Ядро обнуляет подписанные свойства при неудачной проверке HMAC
     * (Screen::boot). Для string это TypeError и 500 вместо экрана.
     */
    public function testSignedIdentifierIsNullable(): void
    {
        self::assertStringContainsString(
            'public ?string $steamid64 = null;',
            self::source(),
            'подписанное свойство ядро может обнулить — тип обязан это допускать'
        );
    }

    /**
     * Перерисовка через yoyo приходит POST-ом: значения фильтров лежат в теле
     * запроса, и одна только query их не видит — фильтры молча сбрасывались бы.
     * Ядро читает request()->input() (attributes + query + body).
     */
    public function testFiltersAreReadFromTheWholeRequest(): void
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../Admin/Package/Screens/Concerns/ResolvesHistory.php'
        );

        self::assertStringNotContainsString('request()->query->all()', $source);
        self::assertStringContainsString('request()->input()', $source);
    }

    private static function source(): string
    {
        return (string) file_get_contents(__DIR__ . '/../Admin/Package/Screens/PlayerCardScreen.php');
    }
}
