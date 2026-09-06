<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ключ перевода, которого нет в файле, во Flute выводится как есть —
 * пользователь видит "connecthistory.sessions.column_map" вместо подписи.
 * Это не падает и потому не замечается до самого прода.
 *
 * Тест сверяет три множества: ключи, использованные в коде, ключи в ru и в en.
 */
final class TranslationKeysTest extends TestCase
{
    /** Ключи, собираемые конкатенацией: их не видно текстовым поиском. */
    private const DYNAMIC_KEYS = [
        'tabs.online', 'tabs.joins', 'tabs.heatmap', 'tabs.newcomers',
        'tabs.maps', 'tabs.reasons', 'tabs.geo', 'tabs.crashes',
        'charts.online_description', 'charts.joins_description',
        'charts.heatmap_description', 'charts.newcomers_description',
        'charts.maps_description', 'charts.reasons_description',
        'charts.geo_description', 'charts.crashes_description',

        // Виджет: показатель и период выбираются в настройках, ключи собираются
        // конкатенацией и в исходниках текстом не встречаются.
        'widget.metrics.online_now', 'widget.metrics.players', 'widget.metrics.sessions',
        'widget.metrics.newcomers', 'widget.metrics.peak_online', 'widget.metrics.avg_session',
        'widget.metrics.total_hours', 'widget.metrics.retention', 'widget.metrics.crashes',
        'widget.periods.1d', 'widget.periods.7d', 'widget.periods.30d',
        'widget.periods.90d', 'widget.periods.180d', 'widget.periods.365d',

        // Подсказки у графиков «Обзора»: ключ собирается из имени активной вкладки.
        'help.chart_online', 'help.chart_joins', 'help.chart_heatmap',
        'help.chart_newcomers', 'help.chart_maps', 'help.chart_reasons',
        'help.chart_geo', 'help.chart_crashes',
    ];

    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /** @return array<string, mixed> */
    private static function load(string $locale): array
    {
        return require self::root() . "/Resources/lang/{$locale}/connecthistory.php";
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<int, string>
     */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $keys = [];

        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $keys = array_merge($keys, self::flatten($value, $path));

                continue;
            }

            $keys[] = $path;
        }

        return $keys;
    }

    /** @return array<int, string> Ключи без префикса connecthistory. */
    private static function usedInCode(): array
    {
        $keys = self::DYNAMIC_KEYS;

        // Не только экраны: диагностика подключений переводит в Services,
        // виджет — в Widgets, формы настроек — во вьюхах.
        $directories = [
            self::root() . '/Admin',
            self::root() . '/Services',
            self::root() . '/Widgets',
            self::root() . '/Resources/views',
        ];

        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                preg_match_all(
                    "/__\('connecthistory\.([a-z_0-9.]+)'/",
                    (string) file_get_contents($file->getPathname()),
                    $matches
                );

                foreach ($matches[1] as $key) {
                    // Обрубки от конкатенации ('...tabs.' . $slug) покрыты DYNAMIC_KEYS.
                    // Разделителем бывает и точка, и подчёркивание — 'help.chart_' . $tab.
                    if (!str_ends_with($key, '.') && !str_ends_with($key, '_')) {
                        $keys[] = $key;
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }

    #[DataProvider('locales')]
    public function testEveryKeyUsedInCodeExists(string $locale): void
    {
        $available = self::flatten(self::load($locale));
        $missing = array_values(array_diff(self::usedInCode(), $available));

        self::assertSame([], $missing, "В переводе {$locale} нет ключей: " . implode(', ', $missing));
    }

    public static function locales(): array
    {
        return ['ru' => ['ru'], 'en' => ['en']];
    }

    public function testLocalesHaveIdenticalKeySets(): void
    {
        $ru = self::flatten(self::load('ru'));
        $en = self::flatten(self::load('en'));

        sort($ru);
        sort($en);

        self::assertSame($en, $ru, 'наборы ключей ru и en должны совпадать');
    }

    public function testNoTranslationValueIsEmpty(): void
    {
        foreach (['ru', 'en'] as $locale) {
            foreach (self::flatten(self::load($locale)) as $key) {
                $value = self::load($locale);

                foreach (explode('.', $key) as $segment) {
                    $value = $value[$segment];
                }

                self::assertIsString($value, "{$locale}.{$key} должен быть строкой");
                self::assertNotSame('', trim($value), "{$locale}.{$key} пустой");
            }
        }
    }

    /**
     * Плейсхолдеры вида :name должны совпадать между языками, иначе на одном
     * из них подстановка молча не произойдёт.
     */
    public function testPlaceholdersMatchAcrossLocales(): void
    {
        $ru = self::load('ru');
        $en = self::load('en');

        foreach (self::flatten($ru) as $key) {
            $ruValue = $ru;
            $enValue = $en;

            foreach (explode('.', $key) as $segment) {
                $ruValue = $ruValue[$segment];
                $enValue = $enValue[$segment];
            }

            preg_match_all('/:[a-z_]+/', (string) $ruValue, $ruPlaceholders);
            preg_match_all('/:[a-z_]+/', (string) $enValue, $enPlaceholders);

            sort($ruPlaceholders[0]);
            sort($enPlaceholders[0]);

            self::assertSame($enPlaceholders[0], $ruPlaceholders[0], "плейсхолдеры расходятся в {$key}");
        }
    }
}
