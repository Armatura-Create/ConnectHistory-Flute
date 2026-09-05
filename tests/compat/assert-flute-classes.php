<?php

declare(strict_types=1);

/**
 * Проверка совместимости с целевой версией Flute.
 *
 * Собирает из исходников модуля все символы Flute\*, на которые он ссылается,
 * и проверяет, что каждый из них существует в установленной версии CMS.
 *
 * Зачем: предыдущий модуль панели умер молча. Класс ModuleInformation переехал
 * из Flute\Core\Modules в Flute\Core\ModulesManager, а Parameter — из Spiral\Database
 * в Cycle\Database. Обе поломки обнаружились в проде: первая при активации,
 * вторая — только при включённом фильтре «новые игроки» (docs/AUTOPSY.md №4 и №5).
 *
 * Запускается в CI после composer require flute-cms/cms.
 * Использование: php tests/compat/assert-flute-classes.php
 */

/**
 * Версия CMS: у корневого пакета её нет в InstalledVersions, поэтому читаем
 * composer.json клона, а при неудаче — не падаем, версия здесь справочная.
 */
function fluteVersion(string $autoload): string
{
    foreach ([dirname($autoload, 2), dirname($autoload, 3) . '/flute-cms/cms'] as $base) {
        $manifest = $base . '/composer.json';

        if (!is_file($manifest)) {
            continue;
        }

        $decoded = json_decode((string) file_get_contents($manifest), true);

        if (($decoded['name'] ?? null) === 'flute-cms/cms') {
            return (string) ($decoded['version'] ?? 'из клона');
        }
    }

    return 'неизвестно';
}

/** Пространство имён самого модуля — из проверки исключается. */
const MODULE_NAMESPACE = 'Flute\\Modules\\ConnectHistory';

$root = dirname(__DIR__, 2);

/*
 * Автозагрузчик Flute берётся отдельно от зависимостей модуля.
 *
 * Причина: flute-cms/cms — пакет типа "project", он не ставится через
 * composer require как библиотека. Плюс его зависимости конфликтуют с
 * инструментами разработки модуля. Поэтому CMS клонируется рядом
 * (в CI — каталог .flute/), а сюда передаётся путь к её автозагрузчику.
 *
 * Порядок поиска: переменная FLUTE_AUTOLOAD -> .flute/vendor -> собственный vendor.
 */
$candidates = array_filter([
    getenv('FLUTE_AUTOLOAD') ?: null,
    $root . '/.flute/vendor/autoload.php',
    $root . '/vendor/autoload.php',
]);

$autoload = null;

foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}

if ($autoload === null) {
    fwrite(
        STDERR,
        "Не найден автозагрузчик. Укажите его через FLUTE_AUTOLOAD или положите\n" .
        "клон Flute в .flute/ рядом с модулем (см. .github/workflows/ci.yml)\n"
    );
    exit(2);
}

require $autoload;

if (!class_exists('Flute\\Core\\Support\\ModuleServiceProvider')) {
    fwrite(
        STDERR,
        "Flute недоступна через {$autoload}: класс Flute\\Core\\Support\\ModuleServiceProvider не найден.\n" .
        "Проверка без CMS бессмысленна — она бы сообщила, что отсутствует вообще всё\n"
    );
    exit(2);
}

/** Каталоги модуля, где могут встречаться ссылки на символы Flute. */
$directories = ['Admin', 'Providers', 'Services'];
$files = [$root . '/Installer.php'];

foreach ($directories as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

/** @var array<string, array<int, string>> символ -> файлы, где он упомянут */
$symbols = [];

foreach ($files as $file) {
    $source = (string) file_get_contents($file);

    // use Flute\...;  и  use Cycle\...;  — импорты покрывают подавляющее большинство ссылок
    preg_match_all('/^use\s+((?:Flute|Cycle)\\\\[A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?\s*;/m', $source, $imports);

    // Полные имена прямо в коде: \Flute\Core\...
    preg_match_all('/\\\\((?:Flute|Cycle)\\\\[A-Za-z0-9_\\\\]+)/', $source, $inline);

    foreach (array_merge($imports[1], $inline[1]) as $symbol) {
        // Собственные классы модуля живут в пространстве имён Flute\Modules\ConnectHistory,
        // но автозагрузчиком CMS не видны и проверяться здесь не должны:
        // задача этой проверки — символы самой Flute.
        if (str_starts_with($symbol, MODULE_NAMESPACE)) {
            continue;
        }

        $symbols[$symbol][] = str_replace($root . '/', '', $file);
    }
}

ksort($symbols);

$missing = [];

foreach ($symbols as $symbol => $usedIn) {
    $exists = class_exists($symbol)
        || interface_exists($symbol)
        || trait_exists($symbol)
        || enum_exists($symbol)
        || function_exists($symbol);

    if (!$exists) {
        $missing[$symbol] = array_values(array_unique($usedIn));
    }
}

$total = count($symbols);

echo 'Flute: ' . fluteVersion($autoload) . "\n";
echo "Проверено символов: {$total}\n";

if ($missing === []) {
    echo "OK: все символы Flute и Cycle на месте\n";

    if (in_array('--verbose', $argv, true)) {
        foreach (array_keys($symbols) as $symbol) {
            echo "  {$symbol}\n";
        }
    }

    exit(0);
}

fwrite(STDERR, "\nНЕ НАЙДЕНЫ в этой версии Flute:\n");

foreach ($missing as $symbol => $usedIn) {
    fwrite(STDERR, "  {$symbol}\n");

    foreach ($usedIn as $file) {
        fwrite(STDERR, "      {$file}\n");
    }
}

fwrite(
    STDERR,
    "\nЛибо символ переехал (посмотрите его новое имя в исходниках Flute),\n" .
    "либо модуль ссылается на то, чего в целевой версии нет.\n"
);

exit(1);
