<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Tests;

use Flute\Modules\ConnectHistory\Services\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Форматирование общее для админ-экранов и виджетов: одно и то же число
 * обязано выглядеть одинаково в обоих местах, иначе расхождение читается
 * как расхождение в данных.
 *
 * __() без загруженной CMS возвращает сам ключ — для этих проверок достаточно:
 * важна структура вывода, а не перевод единиц.
 */
final class FormatTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!function_exists('__')) {
            eval('function __(string $key, array $replace = []): string { return $key; }');
        }
    }

    #[DataProvider('emptyValues')]
    public function testDurationOfNothingIsADash(mixed $value): void
    {
        self::assertSame('—', Format::duration($value));
    }

    public static function emptyValues(): array
    {
        return [
            'ноль' => [0],
            'отрицательное' => [-100],
            'null' => [null],
            'не число' => ['много'],
            'массив' => [[]],
        ];
    }

    public function testDurationGrowsThroughUnits(): void
    {
        // Меньше минуты — всё равно «1 мин»: «0 мин» выглядит как отсутствие данных
        self::assertStringContainsString('1', Format::duration(30));
        self::assertStringContainsString('units.m', Format::duration(600));
        self::assertStringContainsString('units.h', Format::duration(7200));
        self::assertStringContainsString('units.d', Format::duration(200000));
    }

    public function testDurationDoesNotShowDaysBelowADay(): void
    {
        self::assertStringNotContainsString('units.d', Format::duration(86399));
        self::assertStringContainsString('units.d', Format::duration(86400));
    }

    public function testNumberGroupsThousandsWithNonBreakingSpace(): void
    {
        self::assertSame('12' . "\u{00A0}" . '480', Format::number(12480));
        self::assertSame('0', Format::number(0));
        self::assertSame('0', Format::number('мусор'));
    }

    public function testHoursRoundsSecondsToWholeHours(): void
    {
        self::assertSame('2', Format::hours(7200));
        self::assertSame('2', Format::hours(7000));   // округление, а не отбрасывание
        self::assertSame('0', Format::hours(59));
        self::assertSame('1' . "\u{00A0}" . '000', Format::hours(3600 * 1000));
    }

    public function testPercentKeepsFractionButDropsTrailingZero(): void
    {
        self::assertSame('42.5%', Format::percent(42.5));
        self::assertSame('0%', Format::percent(0));
        self::assertSame('0%', Format::percent('мусор'));
    }
}
