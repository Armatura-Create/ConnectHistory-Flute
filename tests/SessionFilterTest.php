<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Flute\Modules\ConnectHistory\Services\SessionFilter;
use PHPUnit\Framework\TestCase;

/**
 * Отказ №1 из docs/AUTOPSY.md: предыдущий модуль позволял запросить 360 дней
 * и тянул всю историю за период в память.
 *
 * Здесь проверяется единственное, что делает это невозможным: окно выборки
 * ограничено ВСЕГДА, чем бы ни было заполнено поле запроса.
 */
final class SessionFilterTest extends TestCase
{
    private const OPTIONS = [
        'max_period_days' => 365,
        'default_period_days' => 7,
        'short_session_seconds' => 60,
    ];

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-04 12:00:00', new DateTimeZone('UTC'));
    }

    private function make(array $input): SessionFilter
    {
        return SessionFilter::fromArray($input, self::OPTIONS);
    }

    public function testDefaultPeriodIsUsedWhenNothingRequested(): void
    {
        self::assertSame(7, $this->make([])->periodDays);
    }

    public function testNamedPeriodsResolveToDays(): void
    {
        self::assertSame(1, $this->make(['period' => '1d'])->periodDays);
        self::assertSame(90, $this->make(['period' => '90d'])->periodDays);
        self::assertSame(365, $this->make(['period' => '365d'])->periodDays);
    }

    /**
     * Ядро отказа №1: сколько бы ни попросили, окно не превысит потолок.
     */
    public function testPeriodIsClampedToMaximum(): void
    {
        self::assertSame(365, $this->make(['period' => 100000])->periodDays);
        self::assertSame(365, $this->make(['period' => '99999'])->periodDays);
    }

    public function testPeriodNeverGoesBelowOneDay(): void
    {
        self::assertSame(1, $this->make(['period' => 0])->periodDays);
        self::assertSame(1, $this->make(['period' => -50])->periodDays);
    }

    public function testGarbagePeriodFallsBackToDefault(): void
    {
        self::assertSame(7, $this->make(['period' => 'вчера'])->periodDays);
        self::assertSame(7, $this->make(['period' => ['array']])->periodDays);
        self::assertSame(7, $this->make(['period' => null])->periodDays);
    }

    /**
     * Явная дата не должна быть лазейкой в обход потолка периода.
     */
    public function testExplicitDateCannotWidenTheWindow(): void
    {
        $filter = $this->make(['period' => '7d', 'date_from' => '2001-01-01']);

        $expected = $this->now()->modify('-7 days');

        self::assertSame(
            $expected->format('Y-m-d H:i:s'),
            $filter->from($this->now())->format('Y-m-d H:i:s'),
            'date_from за пределами окна должен подтягиваться к его началу'
        );
    }

    public function testExplicitDateInsideWindowIsRespected(): void
    {
        $filter = $this->make(['period' => '30d', 'date_from' => '2026-09-01']);

        self::assertSame('2026-09-01 00:00:00', $filter->from($this->now())->format('Y-m-d H:i:s'));
    }

    public function testUpperBoundNeverExceedsNow(): void
    {
        $filter = $this->make(['date_to' => '2030-01-01']);

        self::assertSame(
            $this->now()->format('Y-m-d H:i:s'),
            $filter->to($this->now())->format('Y-m-d H:i:s')
        );
    }

    public function testInvertedRangeIsSwappedInsteadOfProducingEmptyWindow(): void
    {
        $filter = $this->make(['date_from' => '2026-09-03', 'date_to' => '2026-09-01']);

        self::assertSame('2026-09-01', $filter->dateFrom);
        self::assertSame('2026-09-03', $filter->dateTo);
        self::assertLessThan($filter->to($this->now()), $filter->from($this->now()));
    }

    public function testInvalidDatesAreRejected(): void
    {
        self::assertNull($this->make(['date_from' => '2026-02-31'])->dateFrom, 'несуществующая дата');
        self::assertNull($this->make(['date_from' => '04.09.2026'])->dateFrom, 'чужой формат');
        self::assertNull($this->make(['date_from' => "2026-09-01' OR 1=1"])->dateFrom, 'попытка инъекции');
    }

    /**
     * В PCRE якорь $ совпадает и ПЕРЕД завершающим переводом строки. Для date()
     * это имело значение: значение уходит в проверку БЕЗ trim(), и "2026-09-01\n"
     * проходил валидацию, а потом ронял конструктор DateTimeImmutable.
     */
    public function testTrailingNewlineIsRejectedWhereInputIsNotTrimmed(): void
    {
        self::assertNull($this->make(['date_from' => "2026-09-01\n"])->dateFrom);
        self::assertNull($this->make(['date_to' => "2026-09-01\n"])->dateTo);
    }

    /**
     * Текстовые фильтры, наоборот, тримятся до проверки: пробел или перевод строки
     * при вставке из буфера — не повод отбросить осмысленное значение.
     */
    public function testSurroundingWhitespaceIsTrimmedNotRejected(): void
    {
        self::assertSame('RU', $this->make(['country' => "ru\n"])->country);
        self::assertSame('de_dust2', $this->make(['map' => "  de_dust2\t"])->map);
        self::assertTrue($this->make(['search' => " 76561198012345678\n"])->searchIsSteamId());
    }

    /**
     * Значение, прошедшее валидацию, обязано быть пригодным для DateTimeImmutable:
     * иначе фильтр не отсекает мусор, а лишь откладывает падение.
     */
    public function testAcceptedDatesAlwaysBuildValidBounds(): void
    {
        foreach (['2026-09-01', '2026-02-29', '2024-12-31'] as $candidate) {
            $filter = $this->make(['period' => '365d', 'date_from' => $candidate]);

            if ($filter->dateFrom === null) {
                continue;
            }

            self::assertNotEmpty($filter->from($this->now())->format('Y-m-d'));
        }
    }

    public function testStateAndGroupFallBackToSafeDefaults(): void
    {
        self::assertSame(SessionFilter::STATE_ALL, $this->make(['state' => 'drop table'])->state);
        self::assertSame(SessionFilter::GROUP_NONE, $this->make(['group' => '../../etc'])->groupBy);
        self::assertSame(SessionFilter::STATE_ONLINE, $this->make(['state' => 'online'])->state);
        self::assertSame(SessionFilter::GROUP_MAP, $this->make(['group' => 'map'])->groupBy);
    }

    public function testCountryIsNormalisedToTwoLetterCode(): void
    {
        self::assertSame('RU', $this->make(['country' => 'ru'])->country);
        self::assertSame('DE', $this->make(['country' => ' de '])->country);
        self::assertNull($this->make(['country' => 'RUS'])->country);
        self::assertNull($this->make(['country' => '1;'])->country);
    }

    public function testTextFiltersAreTrimmedAndLengthLimited(): void
    {
        self::assertNull($this->make(['map' => '   '])->map);
        self::assertSame(64, mb_strlen((string) $this->make(['map' => str_repeat('a', 500)])->map));
        self::assertSame(128, mb_strlen((string) $this->make(['search' => str_repeat('b', 500)])->search));
    }

    public function testMinutesAreConvertedToSeconds(): void
    {
        self::assertSame(600, $this->make(['min_minutes' => 10])->minDuration);
        self::assertNull($this->make(['min_minutes' => 0])->minDuration);
        self::assertNull($this->make(['min_minutes' => 'много'])->minDuration);
    }

    public function testShortSessionCutoffOnlyAppliesWhenAsked(): void
    {
        self::assertSame(0, $this->make([])->minSessionSeconds);
        self::assertSame(60, $this->make(['skip_short' => '1'])->minSessionSeconds);
        self::assertSame(0, $this->make(['skip_short' => '0'])->minSessionSeconds);
    }

    public function testSteamIdIsRecognisedAndKeptAsString(): void
    {
        $filter = $this->make(['search' => '76561198012345678']);

        self::assertTrue($filter->searchIsSteamId());
        self::assertIsString($filter->search);
        self::assertSame('76561198012345678', $filter->search, 'SteamID64 не должен терять точность');

        self::assertFalse($this->make(['search' => 'Вася'])->searchIsSteamId());
        self::assertFalse($this->make(['search' => '123'])->searchIsSteamId());
    }

    public function testBucketSizeFollowsWindowLength(): void
    {
        self::assertTrue($this->make(['period' => '1d'])->useHourlyBuckets($this->now()));
        self::assertFalse($this->make(['period' => '30d'])->useHourlyBuckets($this->now()));
    }

    public function testCacheKeyIsStableAndSensitive(): void
    {
        $a = $this->make(['period' => '7d', 'map' => 'de_dust2']);
        $b = $this->make(['period' => '7d', 'map' => 'de_dust2']);
        $c = $this->make(['period' => '7d', 'map' => 'de_mirage']);

        self::assertSame($a->cacheKey('joins'), $b->cacheKey('joins'));
        self::assertNotSame($a->cacheKey('joins'), $c->cacheKey('joins'));
        self::assertNotSame($a->cacheKey('joins'), $a->cacheKey('maps'), 'разные запросы — разные ключи');
    }

    public function testHasNarrowingReflectsActiveFilters(): void
    {
        self::assertFalse($this->make(['period' => '30d'])->hasNarrowing());
        self::assertTrue($this->make(['map' => 'de_dust2'])->hasNarrowing());
        self::assertTrue($this->make(['state' => 'crashed'])->hasNarrowing());
    }

    public function testServerIdIsPositiveIntOrNull(): void
    {
        self::assertSame(3, $this->make(['server' => '3'])->serverId);
        self::assertNull($this->make(['server' => '0'])->serverId);
        self::assertNull($this->make(['server' => '-1'])->serverId);
        self::assertNull($this->make(['server' => 'abc'])->serverId);
    }

    public function testSpanDaysIsAtLeastOne(): void
    {
        $filter = $this->make(['date_from' => '2026-09-04', 'date_to' => '2026-09-04']);

        self::assertGreaterThanOrEqual(1, $filter->spanDays($this->now()));
    }
}
