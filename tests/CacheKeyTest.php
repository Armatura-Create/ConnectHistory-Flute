<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Tests;

use Flute\Modules\ConnectHistory\Services\SessionFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ключи кеша не имеют права содержать символы, зарезервированные PSR-6.
 *
 * Цена ошибки здесь несимметрична: Symfony Cache не игнорирует такой ключ,
 * а БРОСАЕТ InvalidArgumentException. Там, где исключение перехватывалось,
 * это выглядело как «просто работает» — но кеша не было вовсе и каждый заход
 * шёл в базу. Там, где не перехватывалось, виджет показывал «данные недоступны».
 *
 * Двоеточие — самый естественный разделитель для ключа, поэтому ошибка
 * повторяема, и проверка нужна постоянная.
 */
final class CacheKeyTest extends TestCase
{
    /** Ровно то, что запрещает Symfony\Component\Cache\CacheItem. */
    private const RESERVED = '{}()/\\@:';

    private static function assertUsableAsCacheKey(string $key): void
    {
        self::assertNotSame('', $key, 'пустой ключ кеша недопустим');
        self::assertFalse(
            strpbrk($key, self::RESERVED),
            "ключ «{$key}» содержит зарезервированные PSR-6 символы " . self::RESERVED
        );
    }

    public function testReservedListMatchesTheOneDeclaredInCode(): void
    {
        self::assertSame(self::RESERVED, SessionFilter::CACHE_RESERVED_CHARACTERS);
    }

    #[DataProvider('scopes')]
    public function testFilterCacheKeysAreUsable(string $scope): void
    {
        self::assertUsableAsCacheKey(SessionFilter::fromArray([])->cacheKey($scope));
    }

    public static function scopes(): array
    {
        return array_map(
            static fn (string $scope) => [$scope],
            [
                'overview-metrics', 'sessions-metrics', 'players-metrics',
                'filter-options', 'servers', 'joins', 'online', 'heatmap',
                'newcomers', 'maps', 'reasons', 'geo', 'crashes',
            ]
        );
    }

    /**
     * Значения фильтров приходят из адресной строки и попадают в хеш, но сам
     * ключ обязан оставаться пригодным при любом вводе.
     */
    #[DataProvider('hostileInput')]
    public function testKeyStaysUsableForAnyUserInput(array $input): void
    {
        self::assertUsableAsCacheKey(SessionFilter::fromArray($input)->cacheKey('sessions'));
    }

    public static function hostileInput(): array
    {
        return [
            'пусто' => [[]],
            'двоеточия в поиске' => [['search' => 'a:b:c']],
            'слеши в карте' => [['map' => 'de/dust2\\x']],
            'скобки' => [['reason' => '{KICKED}(1)']],
            'собака' => [['search' => 'user@example.com']],
            'всё сразу' => [['search' => '{}()/\\@:', 'map' => '{}()/\\@:']],
        ];
    }

    /**
     * Ключ виджета собирается конкатенацией, а не через SessionFilter,
     * поэтому проверяется отдельно — по той же схеме, что в StatWidget::render().
     */
    #[DataProvider('widgetKeys')]
    public function testWidgetCacheKeyIsUsable(string $metric, ?int $server, string $period): void
    {
        $key = 'connecthistory.widget.' . $metric . '.' . ($server ?? 'all') . '.' . $period;

        self::assertUsableAsCacheKey($key);
    }

    public static function widgetKeys(): array
    {
        return [
            'онлайн, все серверы' => ['online_now', null, '30d'],
            'игроки, сервер 2' => ['players', 2, '7d'],
            'возвращаемость, год' => ['retention', 1, '365d'],
        ];
    }

    public function testDifferentFiltersProduceDifferentKeys(): void
    {
        $a = SessionFilter::fromArray(['map' => 'de_dust2'])->cacheKey('sessions');
        $b = SessionFilter::fromArray(['map' => 'de_mirage'])->cacheKey('sessions');

        self::assertNotSame($a, $b);
    }
}
