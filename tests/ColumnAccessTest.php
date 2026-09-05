<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Tests;

use Flute\Modules\ConnectHistory\Services\HistoryRepository;
use PHPUnit\Framework\TestCase;

/**
 * Отказ №3 из docs/AUTOPSY.md: предыдущий модуль не разделял персональные данные
 * и игровую статистику — их просто нечем было разделить.
 *
 * Проверяемое свойство: базовый список колонок не содержит ни одного
 * персонального поля, поэтому запрос без права pii физически не может их вернуть.
 */
final class ColumnAccessTest extends TestCase
{
    public function testBaseColumnsContainNoPersonalData(): void
    {
        $leaked = array_intersect(HistoryRepository::SESSION_COLUMNS, HistoryRepository::PII_COLUMNS);

        self::assertSame([], $leaked, 'персональные колонки не должны быть в общем списке');
    }

    public function testPiiListCoversEverySensitiveColumn(): void
    {
        // Полный перечень персональных колонок ch_sessions по docs/DATABASE.md
        foreach (['player_ip', 'ip_hash', 'ip_subnet', 'city'] as $column) {
            self::assertContains($column, HistoryRepository::PII_COLUMNS, "{$column} должен быть за правом pii");
        }
    }

    public function testGameplayColumnsStayAvailableToEveryone(): void
    {
        foreach (['steamid64', 'nickname', 'started_at', 'duration_seconds', 'kills', 'country_iso'] as $column) {
            self::assertContains($column, HistoryRepository::SESSION_COLUMNS);
        }
    }

    public function testCountryIsPublicButCityIsNot(): void
    {
        // Страна — это аудитория, город — это уже адрес конкретного человека
        self::assertContains('country_iso', HistoryRepository::SESSION_COLUMNS);
        self::assertNotContains('city', HistoryRepository::SESSION_COLUMNS);
    }

    public function testColumnListsHaveNoDuplicates(): void
    {
        self::assertSame(
            array_values(array_unique(HistoryRepository::SESSION_COLUMNS)),
            HistoryRepository::SESSION_COLUMNS
        );
    }
}
